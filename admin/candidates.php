<?php

require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/supabase_storage.php';

admin_require_login();

$pdo = get_db();
$action = $_GET['action'] ?? 'list';
$error = null;

/**
 * Maksimal jumlah foto dalam satu batch upload.
 * Ini bukan batas jumlah foto di database.
 */
const CANDIDATE_BULK_MAX = 50;

/**
 * Ukuran maksimum satu file untuk upload massal.
 *
 * Vercel memiliki batas payload Function 4.5 MB.
 * Kita memakai 2 MB agar ada ruang untuk multipart/form-data,
 * CSRF token, field form, dan overhead request.
 */
const CANDIDATE_BULK_MAX_FILE_BYTES = 2 * 1024 * 1024;

/**
 * Menentukan apakah request berasal dari JavaScript batch uploader.
 */
$isBatchRequest =
    ($_SERVER['HTTP_X_SKANEXA_BATCH'] ?? '') === '1';


/* =========================================================
 * DATA POLLING
 * ========================================================= */

$polls = $pdo
    ->query(
        'SELECT id, title, status
         FROM polls
         ORDER BY id DESC'
    )
    ->fetchAll();

$currentPollId = (int)(
    $_GET['poll_id']
    ?? ($polls[0]['id'] ?? 0)
);


/* =========================================================
 * HELPER SORT ORDER
 * ========================================================= */

/**
 * Sisipkan slot baru pada posisi tertentu.
 *
 * Semua foto pada polling yang memiliki sort_order >= $desired
 * akan digeser +1.
 *
 * $excludeId dipakai ketika sedang mengedit foto yang sudah ada.
 */
function candidates_make_room_for_sort(
    PDO $pdo,
    int $pollId,
    int $desired,
    ?int $excludeId = null
): void {

    if ($pollId <= 0) {
        return;
    }

    $desired = max(0, $desired);

    if ($excludeId !== null && $excludeId > 0) {

        $stmt = $pdo->prepare(
            'UPDATE candidates
             SET sort_order = sort_order + 1
             WHERE poll_id = ?
               AND sort_order >= ?
               AND id <> ?'
        );

        $stmt->execute([
            $pollId,
            $desired,
            $excludeId
        ]);

    } else {

        $stmt = $pdo->prepare(
            'UPDATE candidates
             SET sort_order = sort_order + 1
             WHERE poll_id = ?
               AND sort_order >= ?'
        );

        $stmt->execute([
            $pollId,
            $desired
        ]);
    }
}


/**
 * Menghapus slot lama ketika sebuah kandidat dipindahkan
 * atau urutannya berubah.
 *
 * Contoh:
 * 0,1,2,3,4
 *
 * Kandidat posisi 4 dipindah ke 1:
 *
 * 0,2,3,4 -> lalu kandidat menjadi 1
 */
function candidates_remove_old_sort_slot(
    PDO $pdo,
    int $pollId,
    int $oldSort
): void {

    if ($pollId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE candidates
         SET sort_order = sort_order - 1
         WHERE poll_id = ?
           AND sort_order > ?'
    );

    $stmt->execute([
        $pollId,
        $oldSort
    ]);
}


/**
 * Memindahkan kandidat dalam polling yang sama.
 *
 * Jika:
 * 0,1,2,3,4
 *
 * kandidat 4 -> 1:
 * 0,2,3,4 -> 0,1,2,3,4
 *
 * Jika:
 * kandidat 1 -> 4:
 * 0,1,2,3,4 -> 0,1,2,3,4
 */
function candidates_move_sort_same_poll(
    PDO $pdo,
    int $pollId,
    int $candidateId,
    int $oldSort,
    int $newSort
): void {

    if ($pollId <= 0) {
        return;
    }

    $oldSort = max(0, $oldSort);
    $newSort = max(0, $newSort);

    if ($oldSort === $newSort) {
        return;
    }

    if ($newSort < $oldSort) {

        /*
         * Bergerak ke atas.
         *
         * Contoh:
         * 0,1,2,3,4
         * kandidat 4 -> 1
         *
         * Posisi 1,2,3 digeser +1.
         */
        $stmt = $pdo->prepare(
            'UPDATE candidates
             SET sort_order = sort_order + 1
             WHERE poll_id = ?
               AND sort_order >= ?
               AND sort_order < ?
               AND id <> ?'
        );

        $stmt->execute([
            $pollId,
            $newSort,
            $oldSort,
            $candidateId
        ]);

    } else {

        /*
         * Bergerak ke bawah.
         *
         * Contoh:
         * 0,1,2,3,4
         * kandidat 1 -> 4
         *
         * Posisi 2,3,4 digeser -1.
         */
        $stmt = $pdo->prepare(
            'UPDATE candidates
             SET sort_order = sort_order - 1
             WHERE poll_id = ?
               AND sort_order > ?
               AND sort_order <= ?
               AND id <> ?'
        );

        $stmt->execute([
            $pollId,
            $oldSort,
            $newSort,
            $candidateId
        ]);
    }
}


/* =========================================================
 * UPLOAD ERROR
 * ========================================================= */

function candidate_upload_error_message(int $code): string
{
    return match ($code) {

        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            'Ukuran foto melebihi batas server. Periksa upload_max_filesize dan post_max_size.',

        UPLOAD_ERR_PARTIAL =>
            'Upload terputus. Silakan coba lagi.',

        UPLOAD_ERR_NO_FILE =>
            'Tidak ada file yang diupload.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'Folder temporary server tidak tersedia.',

        UPLOAD_ERR_CANT_WRITE =>
            'Server gagal menulis file temporary.',

        UPLOAD_ERR_EXTENSION =>
            'Upload dihentikan oleh ekstensi PHP.',

        default =>
            'Upload foto gagal (kode ' . $code . ').',
    };
}


/* =========================================================
 * SUPABASE STORAGE DELETE
 * ========================================================= */

/**
 * Menghapus file dari Supabase Storage berdasarkan URL public.
 *
 * Fungsi ini hanya dipanggil server-side.
 * SUPABASE_SERVICE_ROLE_KEY tidak pernah dikirim ke browser.
 */
function candidate_delete_storage_file(
    string $publicUrl
): bool {

    if (
        $publicUrl === '' ||
        !defined('SUPABASE_SERVICE_ROLE_KEY') ||
        SUPABASE_SERVICE_ROLE_KEY === ''
    ) {
        return false;
    }

    $urlParts = parse_url($publicUrl);

    if (
        !is_array($urlParts) ||
        empty($urlParts['path'])
    ) {
        return false;
    }

    $path = (string)$urlParts['path'];

    /*
     * Bentuk URL:
     *
     * /storage/v1/object/public/<bucket>/<path>
     */
    $prefix =
        '/storage/v1/object/public/'
        . rawurlencode(SUPABASE_STORAGE_BUCKET)
        . '/';

    if (!str_starts_with($path, $prefix)) {

        /*
         * Fallback jika nama bucket memiliki karakter
         * yang berbeda dalam URL.
         */
        $prefixPlain =
            '/storage/v1/object/public/'
            . SUPABASE_STORAGE_BUCKET
            . '/';

        if (!str_starts_with($path, $prefixPlain)) {
            return false;
        }

        $storagePath = substr(
            $path,
            strlen($prefixPlain)
        );

    } else {

        $storagePath = substr(
            $path,
            strlen($prefix)
        );
    }

    if ($storagePath === '') {
        return false;
    }

    $storagePath = implode(
        '/',
        array_map(
            'rawurldecode',
            explode('/', $storagePath)
        )
    );

    $deleteUrl =
        SUPABASE_URL .
        '/storage/v1/object/' .
        rawurlencode(SUPABASE_STORAGE_BUCKET) .
        '/' .
        str_replace(
            '%2F',
            '/',
            rawurlencode($storagePath)
        );

    $ch = curl_init($deleteUrl);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' .
                SUPABASE_SERVICE_ROLE_KEY,

            'apikey: ' .
                SUPABASE_SERVICE_ROLE_KEY,
        ],

        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {

        error_log(
            'Supabase Storage delete failed: ' .
            $curlError
        );

        return false;
    }

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        error_log(
            'Supabase Storage delete failed. HTTP ' .
            $httpCode .
            ': ' .
            $response
        );

        return false;
    }

    return true;
}


/* =========================================================
 * HANDLE CANDIDATE UPLOAD
 * ========================================================= */

function handle_candidate_upload(
    ?array $file,
    string $description = ''
): ?string {

    if (
        empty($file) ||
        !isset($file['error']) ||
        (int)$file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (
        (int)$file['error'] !==
        UPLOAD_ERR_OK
    ) {

        throw new RuntimeException(
            candidate_upload_error_message(
                (int)$file['error']
            )
        );
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException(
            'File foto kosong atau rusak.'
        );
    }

    if ($size > MAX_UPLOAD_SIZE) {

        throw new RuntimeException(
            'Ukuran foto maksimal ' .
            (int)(
                MAX_UPLOAD_SIZE /
                1024 /
                1024
            ) .
            ' MB.'
        );
    }

    if (!is_uploaded_file(
        $file['tmp_name'] ?? ''
    )) {

        throw new RuntimeException(
            'File upload tidak valid.'
        );
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(
        FILEINFO_MIME_TYPE
    );

    $mime = $finfo->file(
        $file['tmp_name']
    );

    if (!isset($allowed[$mime])) {

        throw new RuntimeException(
            'Format foto harus JPG, PNG, atau WEBP. ' .
            'Format terdeteksi: ' .
            ($mime ?: 'unknown') .
            '.'
        );
    }

    $ext = $allowed[$mime];

    $filename =
        bin2hex(
            random_bytes(12)
        ) .
        '.' .
        $ext;

    /*
     * Jangan menyimpan file ke filesystem Vercel.
     * Upload langsung ke Supabase Storage.
     */
    return supabase_storage_upload(
        $file['tmp_name'],
        'candidates/' . $filename,
        $mime
    );
}


/* =========================================================
 * NORMALIZE MULTI FILES
 * ========================================================= */

/**
 * @return list<array{
 *     name:string,
 *     type:string,
 *     tmp_name:string,
 *     error:int,
 *     size:int
 * }>
 */
function normalize_multi_files(
    array $filesField
): array {

    $out = [];

    if (
        !isset($filesField['name']) ||
        !is_array($filesField['name'])
    ) {

        if (!empty($filesField['name'])) {

            $out[] = [
                'name' =>
                    (string)$filesField['name'],

                'type' =>
                    (string)(
                        $filesField['type']
                        ?? ''
                    ),

                'tmp_name' =>
                    (string)(
                        $filesField['tmp_name']
                        ?? ''
                    ),

                'error' =>
                    (int)(
                        $filesField['error']
                        ?? UPLOAD_ERR_NO_FILE
                    ),

                'size' =>
                    (int)(
                        $filesField['size']
                        ?? 0
                    ),
            ];
        }

        return $out;
    }

    $n = count(
        $filesField['name']
    );

    for ($i = 0; $i < $n; $i++) {

        $errorCode = (int)(
            $filesField['error'][$i]
            ?? UPLOAD_ERR_NO_FILE
        );

        if (
            $errorCode ===
            UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $out[] = [
            'name' =>
                (string)(
                    $filesField['name'][$i]
                    ?? 'foto'
                ),

            'type' =>
                (string)(
                    $filesField['type'][$i]
                    ?? ''
                ),

            'tmp_name' =>
                (string)(
                    $filesField['tmp_name'][$i]
                    ?? ''
                ),

            'error' =>
                $errorCode,

            'size' =>
                (int)(
                    $filesField['size'][$i]
                    ?? 0
                ),
        ];
    }

    return $out;
}


/* =========================================================
 * JSON RESPONSE UNTUK BATCH
 * ========================================================= */

function candidate_batch_json(
    bool $success,
    int $ok,
    int $fail,
    array $errors = []
): never {

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    http_response_code(
        $success ? 200 : 400
    );

    echo json_encode(
        [
            'success' => $success,
            'ok' => $ok,
            'failed' => $fail,
            'errors' => array_values(
                array_slice(
                    $errors,
                    0,
                    8
                )
            ),
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * UPLOAD MASSAL
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $action === 'bulk_add'
) {

    csrf_verify();

    $pollId = (int)(
        $_POST['poll_id'] ?? 0
    );

    $status =
        (
            ($_POST['status'] ?? 'active')
            === 'inactive'
        )
            ? 'inactive'
            : 'active';

    $baseSort = max(
        0,
        (int)(
            $_POST['sort_order'] ?? 0
        )
    );

    $sortOffset = max(
        0,
        (int)(
            $_POST['sort_offset'] ?? 0
        )
    );

    if ($pollId <= 0) {

        if ($isBatchRequest) {
            candidate_batch_json(
                false,
                0,
                0,
                [
                    'Polling tujuan tidak dipilih.'
                ]
            );
        }

        $error =
            'Pilih polling tujuan terlebih dahulu.';

    } else {

        $pollCheck = $pdo->prepare(
            'SELECT COUNT(*)
             FROM polls
             WHERE id = ?'
        );

        $pollCheck->execute([
            $pollId
        ]);

        if (
            (int)$pollCheck->fetchColumn()
            !== 1
        ) {

            if ($isBatchRequest) {
                candidate_batch_json(
                    false,
                    0,
                    0,
                    [
                        'Polling tujuan tidak ditemukan.'
                    ]
                );
            }

            $error =
                'Polling tujuan tidak ditemukan.';

        } else {

            $files =
                normalize_multi_files(
                    $_FILES['images'] ?? []
                );

            if (!$files) {

                if ($isBatchRequest) {
                    candidate_batch_json(
                        false,
                        0,
                        0,
                        [
                            'Pilih minimal satu foto.'
                        ]
                    );
                }

                $error =
                    'Pilih minimal satu foto untuk diupload.';

            } elseif (
                count($files) >
                CANDIDATE_BULK_MAX
            ) {

                if ($isBatchRequest) {
                    candidate_batch_json(
                        false,
                        0,
                        count($files),
                        [
                            'Maksimal ' .
                            CANDIDATE_BULK_MAX .
                            ' foto per batch.'
                        ]
                    );
                }

                $error =
                    'Maksimal ' .
                    CANDIDATE_BULK_MAX .
                    ' foto per batch.';

            } else {

                $ok = 0;
                $fail = 0;
                $errors = [];

                $stmt = $pdo->prepare(
                    'INSERT INTO candidates
                    (
                        poll_id,
                        name,
                        image_url,
                        description,
                        status,
                        sort_order
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                foreach (
                    $files as $idx => $file
                ) {

                    $origName =
                        (string)(
                            $file['name']
                            ?? 'foto'
                        );

                    $label = pathinfo(
                        $origName,
                        PATHINFO_FILENAME
                    );

                    $label = trim(
                        preg_replace(
                            '/[_\-]+/',
                            ' ',
                            $label
                        ) ?? $label
                    );

                    if ($label === '') {

                        $label =
                            'Foto ' .
                            (
                                $sortOffset +
                                $idx +
                                1
                            );
                    }

                    if (
                        mb_strlen($label) >
                        150
                    ) {

                        $label =
                            mb_substr(
                                $label,
                                0,
                                150
                            );
                    }

                    $imageUrl = null;

                    try {

                        /*
                         * Safety check untuk ukuran
                         * file batch.
                         */
                        if (
                            (int)$file['size'] >
                            CANDIDATE_BULK_MAX_FILE_BYTES
                        ) {

                            throw new RuntimeException(
                                'Ukuran file melebihi 2 MB.'
                            );
                        }

                        $imageUrl =
                            handle_candidate_upload(
                                $file
                            );

                        if (!$imageUrl) {

                            throw new RuntimeException(
                                'File tidak terbaca.'
                            );
                        }

                        /*
                         * Posisi foto dalam seluruh
                         * upload massal.
                         */
                        $thisSort =
                            $baseSort +
                            $sortOffset +
                            $idx;

                        /*
                         * Sisipkan slot.
                         */
                        candidates_make_room_for_sort(
                            $pdo,
                            $pollId,
                            $thisSort,
                            null
                        );

                        $stmt->execute([
                            $pollId,
                            $label,
                            $imageUrl,
                            '',
                            $status,
                            $thisSort
                        ]);

                        $ok++;

                    } catch (Throwable $ex) {

                        /*
                         * Jika file sudah masuk Storage
                         * tetapi database gagal, bersihkan.
                         */
                        if (
                            $imageUrl
                            &&
                            is_string($imageUrl)
                        ) {

                            candidate_delete_storage_file(
                                $imageUrl
                            );
                        }

                        $fail++;

                        if (
                            count($errors) < 8
                        ) {

                            $errors[] =
                                $origName .
                                ': ' .
                                $ex->getMessage();
                        }
                    }
                }

                /*
                 * Request batch dari JavaScript:
                 * JANGAN redirect.
                 *
                 * Browser membutuhkan JSON agar
                 * dapat melanjutkan batch berikutnya.
                 */
                if ($isBatchRequest) {

                    if ($ok > 0) {

                        admin_audit(
                            'candidate.bulk_create',
                            'poll',
                            $pollId,
                            $ok .
                            ' foto ditambahkan via batch upload'
                        );
                    }

                    candidate_batch_json(
                        $fail === 0,
                        $ok,
                        $fail,
                        $errors
                    );
                }

                /*
                 * Submit normal/non-JavaScript.
                 */
                if ($ok > 0) {

                    admin_audit(
                        'candidate.bulk_create',
                        'poll',
                        $pollId,
                        $ok .
                        ' foto ditambahkan via upload massal'
                    );

                    flash_set(
                        'success',
                        $ok .
                        ' foto berhasil ditambahkan.'
                    );

                    if ($fail > 0) {

                        flash_set(
                            'error',
                            $fail .
                            ' foto gagal. ' .
                            implode(
                                ' | ',
                                $errors
                            )
                        );
                    }

                    redirect(
                        'candidates.php?poll_id=' .
                        $pollId
                    );

                } else {

                    $error =
                        'Semua upload gagal. ' .
                        implode(
                            ' | ',
                            $errors
                        );

                    if (empty($errors)) {

                        $error =
                            'Semua upload gagal. Periksa file dan konfigurasi upload.';
                    }
                }
            }
        }
    }

    $currentPollId =
        $pollId > 0
            ? $pollId
            : $currentPollId;
}


/* =========================================================
 * TAMBAH / EDIT SATUAN
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    in_array(
        $action,
        ['add', 'edit'],
        true
    )
) {

    csrf_verify();

    $name = trim(
        (string)(
            $_POST['name'] ?? ''
        )
    );

    $pollId = (int)(
        $_POST['poll_id'] ?? 0
    );

    $description = trim(
        (string)(
            $_POST['description']
            ?? ''
        )
    );

    $sortOrder = max(
        0,
        (int)(
            $_POST['sort_order']
            ?? 0
        )
    );

    $status =
        (
            $_POST['status'] ?? 'active'
        ) === 'inactive'
            ? 'inactive'
            : 'active';

    $id = (int)(
        $_POST['id'] ?? 0
    );

    $imageUrl = null;

    if (
        $name === '' ||
        $pollId <= 0
    ) {

        $error =
            'Nama foto dan polling wajib diisi.';

    } else {

        try {

            /*
             * Upload dulu jika ada file baru.
             */
            $imageUrl =
                handle_candidate_upload(
                    $_FILES['image'] ?? null
                );

            /* -----------------------------------------
             * TAMBAH
             * ----------------------------------------- */
            if ($action === 'add') {

                if (!$imageUrl) {

                    throw new RuntimeException(
                        'Foto wajib diupload untuk kandidat baru.'
                    );
                }

                $pollCheck =
                    $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM polls
                         WHERE id = ?'
                    );

                $pollCheck->execute([
                    $pollId
                ]);

                if (
                    (int)$pollCheck->fetchColumn()
                    !== 1
                ) {

                    throw new RuntimeException(
                        'Polling tujuan tidak ditemukan.'
                    );
                }

                candidates_make_room_for_sort(
                    $pdo,
                    $pollId,
                    $sortOrder,
                    null
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO candidates
                    (
                        poll_id,
                        name,
                        image_url,
                        description,
                        status,
                        sort_order
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $pollId,
                    $name,
                    $imageUrl,
                    $description,
                    $status,
                    $sortOrder
                ]);

                $newId =
                    (int)db_last_insert_id(
                        $pdo
                    );

                admin_audit(
                    'candidate.create',
                    'candidate',
                    $newId,
                    $name
                );

                flash_set(
                    'success',
                    'Foto kandidat berhasil ditambahkan.'
                );

                redirect(
                    'candidates.php?poll_id=' .
                    $pollId
                );
            }


            /* -----------------------------------------
             * EDIT
             * ----------------------------------------- */

            $oldStmt = $pdo->prepare(
                'SELECT
                    id,
                    poll_id,
                    name,
                    image_url,
                    sort_order
                 FROM candidates
                 WHERE id = ?
                 LIMIT 1'
            );

            $oldStmt->execute([
                $id
            ]);

            $oldRow =
                $oldStmt->fetch();

            if (!$oldRow) {

                throw new RuntimeException(
                    'Data foto tidak ditemukan.'
                );
            }

            $oldPollId =
                (int)$oldRow['poll_id'];

            $oldSort =
                (int)$oldRow['sort_order'];

            $oldImageUrl =
                (string)(
                    $oldRow['image_url']
                    ?? ''
                );

            /*
             * Periksa polling baru.
             */
            $pollCheck =
                $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM polls
                     WHERE id = ?'
                );

            $pollCheck->execute([
                $pollId
            ]);

            if (
                (int)$pollCheck->fetchColumn()
                !== 1
            ) {

                throw new RuntimeException(
                    'Polling tujuan tidak ditemukan.'
                );
            }

            /*
             * Kandidat yang sudah memiliki vote
             * tidak boleh mengganti nama atau foto.
             */
            $voteCheck =
                $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM votes
                     WHERE candidate_id = ?'
                );

            $voteCheck->execute([
                $id
            ]);

            $hasVotes =
                (int)$voteCheck->fetchColumn()
                > 0;

            if ($hasVotes) {

                if (
                    $name !==
                    (string)$oldRow['name']
                    ||
                    $imageUrl
                ) {

                    throw new RuntimeException(
                        'Foto yang sudah memiliki vote tidak boleh mengganti nama atau file fotonya. Ubah status, urutan, atau deskripsi saja.'
                    );
                }
            }

            /*
             * Atur sort order dengan benar.
             */
            if ($oldPollId === $pollId) {

                if ($oldSort !== $sortOrder) {

                    candidates_move_sort_same_poll(
                        $pdo,
                        $pollId,
                        $id,
                        $oldSort,
                        $sortOrder
                    );
                }

            } else {

                /*
                 * Pindah polling:
                 *
                 * 1. Tutup slot lama.
                 * 2. Buka slot baru.
                 */
                candidates_remove_old_sort_slot(
                    $pdo,
                    $oldPollId,
                    $oldSort
                );

                candidates_make_room_for_sort(
                    $pdo,
                    $pollId,
                    $sortOrder,
                    $id
                );
            }

            /*
             * Update database.
             */
            if ($imageUrl) {

                $stmt = $pdo->prepare(
                    'UPDATE candidates
                     SET
                        poll_id = ?,
                        name = ?,
                        image_url = ?,
                        description = ?,
                        status = ?,
                        sort_order = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $pollId,
                    $name,
                    $imageUrl,
                    $description,
                    $status,
                    $sortOrder,
                    $id
                ]);

            } else {

                $stmt = $pdo->prepare(
                    'UPDATE candidates
                     SET
                        poll_id = ?,
                        name = ?,
                        description = ?,
                        status = ?,
                        sort_order = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $pollId,
                    $name,
                    $description,
                    $status,
                    $sortOrder,
                    $id
                ]);
            }

            /*
             * Hapus file lama dari Supabase
             * setelah database berhasil diperbarui.
             */
            if (
                $imageUrl &&
                $oldImageUrl &&
                $oldImageUrl !== $imageUrl
            ) {

                candidate_delete_storage_file(
                    $oldImageUrl
                );
            }

            admin_audit(
                'candidate.update',
                'candidate',
                $id,
                $name
            );

            flash_set(
                'success',
                'Foto kandidat berhasil diperbarui.'
            );

            redirect(
                'candidates.php?poll_id=' .
                $pollId
            );

        } catch (Throwable $ex) {

            /*
             * Jika upload baru berhasil tetapi
             * proses database gagal, hapus dari Storage.
             */
            if (
                !empty($imageUrl) &&
                is_string($imageUrl)
            ) {

                candidate_delete_storage_file(
                    $imageUrl
                );
            }

            $error =
                $ex->getMessage();
        }
    }

    $currentPollId =
        $pollId > 0
            ? $pollId
            : $currentPollId;
}


/* =========================================================
 * HAPUS
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $action === 'delete'
) {

    csrf_verify();

    $id = (int)(
        $_POST['id'] ?? 0
    );

    /*
     * Pastikan kandidat ada.
     */
    $candidateStmt =
        $pdo->prepare(
            'SELECT
                id,
                poll_id,
                image_url,
                name
             FROM candidates
             WHERE id = ?
             LIMIT 1'
        );

    $candidateStmt->execute([
        $id
    ]);

    $candidate =
        $candidateStmt->fetch();

    if (!$candidate) {

        flash_set(
            'error',
            'Foto kandidat tidak ditemukan.'
        );

        redirect(
            'candidates.php?poll_id=' .
            $currentPollId
        );
    }

    $candidatePollId =
        (int)$candidate['poll_id'];

    $currentPollId =
        $candidatePollId;

    /*
     * Jangan hapus kandidat yang sudah
     * memiliki vote.
     */
    $voteCheck =
        $pdo->prepare(
            'SELECT COUNT(*)
             FROM votes
             WHERE candidate_id = ?'
        );

    $voteCheck->execute([
        $id
    ]);

    if (
        (int)$voteCheck->fetchColumn()
        > 0
    ) {

        flash_set(
            'error',
            'Foto tidak dapat dihapus karena sudah memiliki vote. Nonaktifkan saja foto ini.'
        );

    } else {

        $imageUrl =
            (string)(
                $candidate['image_url']
                ?? ''
            );

        /*
         * Ambil posisi lama agar urutan
         * berikutnya dapat dirapikan.
         */
        $sortStmt =
            $pdo->prepare(
                'SELECT sort_order
                 FROM candidates
                 WHERE id = ?
                 LIMIT 1'
            );

        $sortStmt->execute([
            $id
        ]);

        $oldSort =
            (int)(
                $sortStmt->fetchColumn()
                ?: 0
            );

        /*
         * Hapus dari database terlebih dahulu.
         */
        $deleteStmt =
            $pdo->prepare(
                'DELETE FROM candidates
                 WHERE id = ?'
            );

        $deleteStmt->execute([
            $id
        ]);

        /*
         * Rapikan sort order setelah
         * kandidat dihapus.
         */
        $pdo->prepare(
            'UPDATE candidates
             SET sort_order = sort_order - 1
             WHERE poll_id = ?
               AND sort_order > ?'
        )->execute([
            $candidatePollId,
            $oldSort
        ]);

        /*
         * Hapus file dari Supabase Storage.
         */
        if ($imageUrl !== '') {

            $deleted =
                candidate_delete_storage_file(
                    $imageUrl
                );

            if (!$deleted) {

                error_log(
                    'Candidate image could not be deleted from Supabase Storage: ' .
                    $imageUrl
                );
            }
        }

        admin_audit(
            'candidate.delete',
            'candidate',
            $id,
            'Foto kandidat dihapus'
        );

        flash_set(
            'success',
            'Foto kandidat berhasil dihapus.'
        );
    }

    redirect(
        'candidates.php?poll_id=' .
        $currentPollId
    );
}


/* =========================================================
 * EDIT DATA
 * ========================================================= */

$editRow = null;

if ($action === 'edit') {

    $id = (int)(
        $_GET['id'] ?? 0
    );

    $stmt =
        $pdo->prepare(
            'SELECT *
             FROM candidates
             WHERE id = ?'
        );

    $stmt->execute([
        $id
    ]);

    $editRow =
        $stmt->fetch()
        ?: null;

    if ($editRow) {

        $currentPollId =
            (int)$editRow['poll_id'];
    }
}


/* =========================================================
 * DAFTAR KANDIDAT
 * ========================================================= */

$candidates = [];

if ($currentPollId > 0) {

    $stmt =
        $pdo->prepare(
            'SELECT *
             FROM candidates
             WHERE poll_id = ?
             ORDER BY sort_order ASC, id ASC'
        );

    $stmt->execute([
        $currentPollId
    ]);

    $candidates =
        $stmt->fetchAll();
}


/* =========================================================
 * INFO UPLOAD
 * ========================================================= */

$maxFileUploads =
    (int)ini_get(
        'max_file_uploads'
    );

$uploadMax =
    ini_get(
        'upload_max_filesize'
    );

$postMax =
    ini_get(
        'post_max_size'
    );


/* =========================================================
 * HEADER
 * ========================================================= */

$active_menu = 'candidates';
$page_title = 'Kelola Foto';

include __DIR__ .
    '/includes/header.php';
?>


<div class="admin-topbar">

    <h1>
        Kelola Foto Polling
    </h1>

    <div class="muted">
        Total di polling ini:
        <strong>
            <?= count($candidates) ?>
        </strong>
        foto · Tidak ada batas jumlah di database
    </div>

</div>


<?php
if (
    $msg = flash_get('success')
):
?>
    <div class="alert success">
        <?= e($msg) ?>
    </div>
<?php
endif;
?>


<?php
if (
    $msg = flash_get('error')
):
?>
    <div class="alert error">
        <?= e($msg) ?>
    </div>
<?php
endif;
?>


<?php
if ($error):
?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php
endif;
?>


<?php if (!$polls): ?>

    <div class="card">

        <div class="alert error">
            Belum ada polling.
            Buat polling dulu di Pengaturan.
        </div>

    </div>

<?php else: ?>


    <!-- =====================================================
         PILIH POLLING
         ===================================================== -->

    <div class="card">

        <form
            method="get"
            class="filter-bar"
        >

            <label style="margin:0">
                Polling
            </label>

            <select
                name="poll_id"
                onchange="this.form.submit()"
            >

                <?php foreach ($polls as $p): ?>

                    <option
                        value="<?= (int)$p['id'] ?>"
                        <?= (
                            $currentPollId ===
                            (int)$p['id']
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($p['title']) ?>
                        —
                        <?= e(
                            ucfirst(
                                $p['status']
                            )
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </form>

    </div>


    <!-- =====================================================
         UPLOAD MASSAL
         ===================================================== -->

    <?php if (!$editRow): ?>

        <div class="card">

            <h3 style="margin-top:0">
                Upload Massal
                (banyak foto sekaligus)
            </h3>

            <p class="muted">

                Pilih hingga
                <strong>
                    <?= (int)CANDIDATE_BULK_MAX ?>
                </strong>
                foto.

                Upload otomatis dibagi menjadi
                beberapa batch agar aman untuk Vercel.

                Nama file digunakan sebagai
                nama kandidat.

                Format:
                JPG / PNG / WEBP.

                Maksimal:
                <strong>
                    2 MB per foto
                </strong>.

            </p>

            <p
                class="muted"
                style="font-size:13px"
            >
                Foto dikirim bertahap ke server,
                kemudian disimpan permanen di
                Supabase Storage.
            </p>


            <form
                method="post"
                action="candidates.php?action=bulk_add"
                enctype="multipart/form-data"
            >

                <?= csrf_field() ?>


                <input
                    type="hidden"
                    name="poll_id"
                    value="<?= (int)$currentPollId ?>"
                >


                <label>
                    Pilih banyak foto
                </label>

                <input
                    type="file"
                    name="images[]"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    multiple
                    required
                >


                <label>
                    Status
                </label>

                <select name="status">

                    <option
                        value="active"
                        selected
                    >
                        Active
                    </option>

                    <option
                        value="inactive"
                    >
                        Inactive
                    </option>

                </select>


                <label>
                    Urutan awal (opsional)
                </label>

                <input
                    type="number"
                    name="sort_order"
                    value="0"
                    min="0"
                    step="1"
                >


                <p
                    class="muted"
                    style="
                        font-size:13px;
                        margin-top:4px
                    "
                >
                    Jika nomor sudah dipakai,
                    foto lama di nomor tersebut
                    dan seterusnya otomatis digeser
                    (+1).
                </p>


                <button
                    type="submit"
                    class="btn"
                >
                    Upload Semua Foto
                </button>

            </form>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         TAMBAH / EDIT SATU FOTO
         ===================================================== -->

    <div
        class="card"
        style="max-width:520px;"
    >

        <h3 style="margin-top:0">

            <?= $editRow
                ? 'Edit Foto'
                : 'Tambah Foto Satu per Satu'
            ?>

        </h3>


        <form
            method="post"
            action="candidates.php?action=<?= $editRow ? 'edit' : 'add' ?>"
            enctype="multipart/form-data"
        >

            <?= csrf_field() ?>


            <?php if ($editRow): ?>

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$editRow['id'] ?>"
                >

            <?php endif; ?>


            <label>
                Polling
            </label>

            <select
                name="poll_id"
                required
            >

                <?php foreach ($polls as $p): ?>

                    <option
                        value="<?= (int)$p['id'] ?>"
                        <?= (
                            (
                                $editRow['poll_id']
                                ?? $currentPollId
                            ) == $p['id']
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($p['title']) ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <label>
                Nama
            </label>

            <input
                type="text"
                name="name"
                required
                maxlength="150"
                value="<?= e(
                    $editRow['name']
                    ?? ''
                ) ?>"
            >


            <label>
                Deskripsi (opsional)
            </label>

            <textarea
                name="description"
                rows="2"
            ><?= e(
                $editRow['description']
                ?? ''
            ) ?></textarea>


            <label>

                Foto
                (<?= $editRow
                    ? 'kosongkan jika tidak ingin mengganti'
                    : 'JPG/PNG/WEBP, maks ' .
                      (int)(
                          MAX_UPLOAD_SIZE /
                          1024 /
                          1024
                      ) .
                      ' MB'
                ?>)

            </label>

            <input
                type="file"
                name="image"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                <?= $editRow
                    ? ''
                    : 'required'
                ?>
            >


            <label>
                Urutan Tampil
            </label>

            <input
                type="number"
                name="sort_order"
                value="<?= e(
                    (string)(
                        $editRow['sort_order']
                        ?? 0
                    )
                ) ?>"
                min="0"
                step="1"
            >


            <p
                class="muted"
                style="
                    font-size:13px;
                    margin-top:4px
                "
            >
                Jika nomor sudah dipakai
                foto lain, foto tersebut
                akan digeser otomatis.
            </p>


            <label>
                Status
            </label>

            <select name="status">

                <option
                    value="active"
                    <?= (
                        !$editRow ||
                        $editRow['status']
                        === 'active'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Active
                </option>

                <option
                    value="inactive"
                    <?= (
                        $editRow &&
                        $editRow['status']
                        === 'inactive'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Inactive
                </option>

            </select>


            <button
                type="submit"
                class="btn"
            >
                <?= $editRow
                    ? 'Simpan Perubahan'
                    : 'Tambah Foto'
                ?>
            </button>


            <?php if ($editRow): ?>

                <a
                    href="candidates.php?poll_id=<?= (int)$currentPollId ?>"
                    class="btn secondary"
                >
                    Batal
                </a>

            <?php endif; ?>

        </form>

    </div>


    <!-- =====================================================
         DAFTAR FOTO
         ===================================================== -->

    <div class="card">

        <h3 style="margin-top:0">

            Daftar Foto
            (<?= count($candidates) ?>)

        </h3>


        <table>

            <thead>

                <tr>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>Urutan</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>

            </thead>


            <tbody>

            <?php foreach ($candidates as $c): ?>

                <tr>

                    <td>

                        <?php
                        if (
                            !empty(
                                $c['image_url']
                            )
                        ):
                        ?>

                            <img
                                src="<?= e(
                                    $c['image_url']
                                ) ?>"
                                alt="<?= e(
                                    $c['name']
                                ) ?>"
                                loading="lazy"
                                style="
                                    width:50px;
                                    height:66px;
                                    object-fit:cover;
                                    border-radius:6px;
                                "
                            >

                        <?php endif; ?>

                    </td>


                    <td>
                        <?= e(
                            $c['name']
                        ) ?>
                    </td>


                    <td>
                        <?= (int)(
                            $c['sort_order']
                        ) ?>
                    </td>


                    <td>

                        <span
                            class="badge <?= e(
                                $c['status']
                            ) ?>"
                        >
                            <?= $c['status'] === 'active'
                                ? 'Active'
                                : 'Inactive'
                            ?>
                        </span>

                    </td>


                    <td>

                        <a
                            href="candidates.php?action=edit&id=<?= (int)$c['id'] ?>&amp;poll_id=<?= (int)$currentPollId ?>"
                            class="btn small secondary"
                        >
                            Edit
                        </a>


                        <form
                            method="post"
                            action="candidates.php?action=delete"
                            style="display:inline"
                            onsubmit="return confirm('Hapus foto ini?');"
                        >

                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$c['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn small danger"
                            >
                                Hapus
                            </button>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (
                empty($candidates)
            ): ?>

                <tr>

                    <td
                        colspan="5"
                        class="muted"
                    >
                        Belum ada foto
                        untuk polling ini.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

<?php endif; ?>


<script>
(function () {

    const form = document.querySelector(
        'form[action="candidates.php?action=bulk_add"]'
    );

    if (!form) {
        return;
    }

    const input = form.querySelector(
        'input[type="file"][name="images[]"]'
    );

    const submitButton = form.querySelector(
        'button[type="submit"]'
    );

    /*
     * Vercel saat ini memiliki batas
     * payload Function sebesar 4.5 MB.
     *
     * Kita sengaja memakai 2 MB sebagai
     * batas total ukuran file per batch.
     *
     * Ini memberi ruang untuk:
     * - multipart/form-data
     * - CSRF token
     * - poll_id
     * - status
     * - sort_order
     * - nama file
     * - HTTP overhead
     */
    const MAX_BATCH_BYTES =
        2 * 1024 * 1024;

    /*
     * Batas satu file.
     */
    const MAX_SINGLE_FILE_BYTES =
        2 * 1024 * 1024;


    function createBatches(files) {

        const batches = [];

        let currentBatch = [];
        let currentSize = 0;


        for (const file of files) {

            if (
                file.size >
                MAX_SINGLE_FILE_BYTES
            ) {

                throw new Error(
                    'Foto "' +
                    file.name +
                    '" terlalu besar.\n\n' +
                    'Maksimal 2 MB per foto untuk upload massal.'
                );
            }


            /*
             * Jika file berikutnya membuat
             * batch melewati batas, simpan
             * batch saat ini dan mulai baru.
             */
            if (
                currentBatch.length > 0 &&
                currentSize + file.size >
                MAX_BATCH_BYTES
            ) {

                batches.push(
                    currentBatch
                );

                currentBatch = [];
                currentSize = 0;
            }


            currentBatch.push(file);

            currentSize += file.size;
        }


        if (
            currentBatch.length > 0
        ) {

            batches.push(
                currentBatch
            );
        }


        return batches;
    }


    function addNormalFields(
        formData
    ) {

        for (
            const element
            of form.elements
        ) {

            if (!element.name) {
                continue;
            }


            /*
             * File ditambahkan manual
             * sesuai batch.
             */
            if (
                element.type === 'file'
            ) {
                continue;
            }


            /*
             * sort_offset dibuat
             * oleh JavaScript.
             */
            if (
                element.name ===
                'sort_offset'
            ) {
                continue;
            }


            /*
             * Jangan kirim checkbox/radio
             * yang tidak dipilih.
             */
            if (
                (
                    element.type ===
                    'checkbox' ||
                    element.type ===
                    'radio'
                ) &&
                !element.checked
            ) {
                continue;
            }


            formData.append(
                element.name,
                element.value
            );
        }
    }


    form.addEventListener(
        'submit',
        async function (event) {

            const files =
                Array.from(
                    input.files || []
                );


            if (!files.length) {
                return;
            }


            /*
             * Hentikan submit normal.
             */
            event.preventDefault();


            submitButton.disabled = true;


            try {

                const batches =
                    createBatches(
                        files
                    );

                const total =
                    batches.length;

                let offset = 0;

                let totalUploaded = 0;


                for (
                    let i = 0;
                    i < total;
                    i++
                ) {

                    const batch =
                        batches[i];


                    const formData =
                        new FormData();


                    /*
                     * Tambahkan:
                     * csrf
                     * poll_id
                     * status
                     * sort_order
                     * dll.
                     */
                    addNormalFields(
                        formData
                    );


                    /*
                     * Posisi batch dalam
                     * seluruh daftar file.
                     */
                    formData.append(
                        'sort_offset',
                        String(offset)
                    );


                    /*
                     * Masukkan file batch.
                     */
                    for (
                        const file
                        of batch
                    ) {

                        formData.append(
                            'images[]',
                            file,
                            file.name
                        );
                    }


                    submitButton.textContent =
                        'Mengupload batch ' +
                        (i + 1) +
                        ' dari ' +
                        total +
                        '...';


                    const response =
                        await fetch(
                            form.action,
                            {
                                method: 'POST',

                                body:
                                    formData,

                                credentials:
                                    'same-origin',

                                /*
                                 * Jangan biarkan
                                 * fetch mengikuti redirect.
                                 *
                                 * Batch harus menerima
                                 * JSON dari PHP.
                                 */
                                redirect:
                                    'manual',

                                headers: {
                                    'X-Skanexa-Batch':
                                        '1',

                                    'Accept':
                                        'application/json'
                                }
                            }
                        );


                    /*
                     * Jika Vercel menolak payload.
                     */
                    if (
                        response.status ===
                        413
                    ) {

                        throw new Error(
                            'Batch ' +
                            (i + 1) +
                            ' terlalu besar. ' +
                            'Coba kurangi ukuran foto.'
                        );
                    }


                    /*
                     * Status HTTP lain
                     * selain 2xx.
                     */
                    if (
                        !response.ok
                    ) {

                        throw new Error(
                            'Batch ' +
                            (i + 1) +
                            ' gagal. HTTP ' +
                            response.status
                        );
                    }


                    let result;

                    try {

                        result =
                            await response.json();

                    } catch (jsonError) {

                        throw new Error(
                            'Server tidak mengirim respons JSON yang valid untuk batch ' +
                            (i + 1) +
                            '.'
                        );
                    }


                    if (
                        !result ||
                        result.success !== true
                    ) {

                        const details =
                            Array.isArray(
                                result?.errors
                            )
                                ? result.errors.join(
                                    '\n'
                                )
                                : 'Tidak ada detail error.';


                        throw new Error(
                            'Batch ' +
                            (i + 1) +
                            ' gagal.\n\n' +
                            details
                        );
                    }


                    totalUploaded +=
                        Number(
                            result.ok || 0
                        );


                    /*
                     * Offset hanya ditambah
                     * setelah batch berhasil.
                     */
                    offset +=
                        batch.length;
                }


                /*
                 * Semua batch berhasil.
                 */
                const pollId =
                    form.querySelector(
                        '[name="poll_id"]'
                    ).value;


                submitButton.textContent =
                    'Upload selesai.';


                window.location.href =
                    'candidates.php?poll_id=' +
                    encodeURIComponent(
                        pollId
                    );


            } catch (error) {

                console.error(
                    'SKANEXA bulk upload:',
                    error
                );


                alert(
                    error &&
                    error.message
                        ? error.message
                        : 'Upload gagal.'
                );


                submitButton.disabled =
                    false;


                submitButton.textContent =
                    'Upload Semua Foto';
            }

        }
    );

})();
</script>


<?php

include __DIR__ .
    '/includes/footer.php';

?>
```
