<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DocxPreviewConverter
{
    public const VERSION = 'libreoffice-26';

    public function __construct(private readonly ?string $binary = null) {}

    /**
     * @return array{ok: bool, path: ?string, error: ?string, cached: bool}
     */
    public function convert(string $sourcePath): array
    {
        if (! is_file($sourcePath)) {
            return $this->failure('The original document is not on disk.');
        }

        $binary = $this->binary ?? $this->discoverBinary();
        if ($binary === null || ! is_executable($binary)) {
            return $this->failure('LibreOffice is not available, so the original document cannot be previewed. Download the original file instead.');
        }

        $checksum = hash_file('sha256', $sourcePath);
        $cached = storage_path('app/report-previews/'.$checksum.'/'.self::VERSION.'.pdf');
        if (is_file($cached) && $this->looksLikePdf($cached)) {
            return ['ok' => true, 'path' => $cached, 'error' => null, 'cached' => true];
        }

        $work = sys_get_temp_dir().'/mapr-libreoffice-'.bin2hex(random_bytes(8));
        $profile = $work.'/profile';
        $out = $work.'/out';
        File::ensureDirectoryExists($profile);
        File::ensureDirectoryExists($out);
        $copy = $work.'/source.docx';
        if (! copy($sourcePath, $copy)) {
            File::deleteDirectory($work);

            return $this->failure('The original document could not be copied for conversion.');
        }

        $process = new Process(
            [$binary, '--headless', '--norestore', '--nofirststartwizard', '--convert-to', 'pdf', '--outdir', $out, $copy],
            $work,
            [
                'HOME' => $profile,
                'UserInstallation' => 'file://'.str_replace('%2F', '/', rawurlencode($profile)),
            ],
            null,
            90,
        );

        try {
            $process->run();
            $produced = $out.'/source.pdf';
            $unchanged = hash_file('sha256', $sourcePath) === $checksum;
            if (! $process->isSuccessful() || ! is_file($produced) || ! $this->looksLikePdf($produced) || ! $unchanged) {
                return $this->failure($this->processError($process));
            }

            File::ensureDirectoryExists(dirname($cached));
            if (! copy($produced, $cached)) {
                return $this->failure('The converted preview could not be stored.');
            }

            return ['ok' => true, 'path' => $cached, 'error' => null, 'cached' => false];
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function discoverBinary(): ?string
    {
        foreach (['/usr/bin/soffice', '/usr/bin/libreoffice'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function looksLikePdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }

    private function processError(Process $process): string
    {
        $detail = trim($process->getErrorOutput()."\n".$process->getOutput());
        if ($detail === '') {
            return 'The original document could not be converted to PDF.';
        }

        return 'The original document could not be converted to PDF.';
    }

    /**
     * @return array{ok: bool, path: ?string, error: ?string, cached: bool}
     */
    private function failure(string $error): array
    {
        return ['ok' => false, 'path' => null, 'error' => $error, 'cached' => false];
    }
}
