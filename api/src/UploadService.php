<?php

declare(strict_types=1);

namespace JacSolar;

use RuntimeException;

/**
 * Electricity-bill upload validation and private storage.
 *
 * The bill is mandatory in V1. Only PDF, JPEG and PNG are accepted, at most
 * 10 MB, one file. The real MIME type is derived from file content, never from
 * the client-supplied name or Content-Type.
 */
final class UploadService
{
    public const FIELD = 'electricity_bill';

    /** Real MIME type => canonical extension. */
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    /**
     * Inspect the temporary uploaded file.
     *
     * @param array<string,mixed>|null $file One entry from $_FILES
     *
     * @return array{ok:bool, error?:string, meta?:array{
     *     tmp_path:string,
     *     original_filename:string,
     *     mime:string,
     *     extension:string,
     *     size:int,
     *     sha256:string
     * }}
     */
    public static function inspect(?array $file): array
    {
        if ($file === null || !isset($file['error'])) {
            return self::fail('Please attach your latest electricity bill.');
        }

        // Reject multi-file submissions for this field.
        if (is_array($file['error'])) {
            return self::fail('Please attach exactly one file.');
        }

        $phpError = (int) $file['error'];

        if ($phpError === UPLOAD_ERR_NO_FILE) {
            return self::fail('Please attach your latest electricity bill.');
        }

        if ($phpError === UPLOAD_ERR_INI_SIZE || $phpError === UPLOAD_ERR_FORM_SIZE) {
            return self::fail('That file is too large. The maximum size is 10 MB.');
        }

        if ($phpError !== UPLOAD_ERR_OK) {
            Response::logInternal('upload', 'PHP upload error code ' . $phpError);

            return self::fail('The file could not be uploaded. Please try again.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return self::fail('The file could not be verified. Please try again.');
        }

        $size = (int) (filesize($tmpPath) ?: 0);

        if ($size <= 0) {
            return self::fail('The uploaded file is empty. Please attach a readable bill.');
        }

        $maxBytes = Config::int('max_upload_bytes', 10485760);
        if ($size > $maxBytes) {
            return self::fail('That file is too large. The maximum size is 10 MB.');
        }

        // Real MIME from file content.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            Response::logInternal('upload', 'finfo_open failed');

            return self::fail('The file could not be checked. Please try again.');
        }

        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            return self::fail('Only PDF, JPG, or PNG files are accepted.');
        }

        // Format-specific structural checks.
        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            $imageInfo = @getimagesize($tmpPath);

            if ($imageInfo === false) {
                return self::fail('That image could not be read. Please upload a valid JPG or PNG.');
            }

            $detectedType = $imageInfo[2] ?? 0;
            $expectedType = $mime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;

            if ($detectedType !== $expectedType) {
                return self::fail('That image could not be read. Please upload a valid JPG or PNG.');
            }
        }

        if ($mime === 'application/pdf' && !self::hasPdfSignature($tmpPath)) {
            return self::fail('That PDF could not be read. Please upload a valid PDF.');
        }

        $sha256 = hash_file('sha256', $tmpPath);
        if (!is_string($sha256) || strlen($sha256) !== 64) {
            Response::logInternal('upload', 'hash_file failed');

            return self::fail('The file could not be processed. Please try again.');
        }

        return [
            'ok'   => true,
            'meta' => [
                'tmp_path'          => $tmpPath,
                'original_filename' => self::sanitizeOriginalName((string) ($file['name'] ?? '')),
                'mime'              => $mime,
                'extension'         => self::ALLOWED[$mime],
                'size'              => $size,
                'sha256'            => $sha256,
            ],
        ];
    }

    /**
     * Move the validated temporary file into private storage.
     *
     * @param array{tmp_path:string, extension:string} $meta
     *
     * @return string The randomized stored filename (not a URL, not a path).
     *
     * @throws RuntimeException on any storage failure.
     */
    public static function store(array $meta): string
    {
        $directory = rtrim(Config::string('private_upload_dir'), '/') . '/';

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Private upload directory is not writable.');
        }

        $storedFilename = sprintf(
            '%s_%s.%s',
            date('Ymd'),
            bin2hex(random_bytes(16)),
            $meta['extension']
        );

        $destination = $directory . $storedFilename;

        if (!move_uploaded_file($meta['tmp_path'], $destination)) {
            throw new RuntimeException('Failed to move uploaded file into private storage.');
        }

        // Restrictive permissions where the platform supports them.
        @chmod($destination, 0640);

        return $storedFilename;
    }

    /** Absolute path to a stored bill. Internal use only, never returned to clients. */
    public static function pathFor(string $storedFilename): string
    {
        return rtrim(Config::string('private_upload_dir'), '/') . '/' . $storedFilename;
    }

    /** Remove a stored bill. Used only to undo a failed database transaction. */
    public static function remove(string $storedFilename): void
    {
        if ($storedFilename === '') {
            return;
        }

        $path = self::pathFor($storedFilename);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function hasPdfSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }

    /**
     * Keep the original filename as metadata only. Path separators and control
     * characters are stripped; the value is never used to build a real path.
     */
    private static function sanitizeOriginalName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = preg_replace('/[[:cntrl:]]/u', '', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            $name = 'electricity-bill';
        }

        return mb_substr($name, 0, 255, 'UTF-8');
    }

    /**
     * @return array{ok:bool, error:string}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
