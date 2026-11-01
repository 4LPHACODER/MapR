<?php

namespace App\Support;

use Generator;
use RuntimeException;

class ShapefileReader
{
    private ?string $encoding = null;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return list<array{name: string, length: int}>
     */
    public function fields(): array
    {
        return $this->dbfFields();
    }

    /**
     * @return Generator<int, array{attrs: array<string, string>, geometry: ?array}>
     */
    public function records(): Generator
    {
        $shp = fopen($this->basePath.'.shp', 'rb');
        $dbf = fopen($this->basePath.'.dbf', 'rb');

        if ($shp === false || $dbf === false) {
            throw new RuntimeException('Unable to open shapefile '.$this->basePath);
        }

        $header = fread($dbf, 32);
        $recordCount = unpack('V', substr($header, 4, 4))[1];
        $headerLength = unpack('v', substr($header, 8, 2))[1];
        $recordLength = unpack('v', substr($header, 10, 2))[1];
        $fields = $this->dbfFieldsFromHandle($dbf);
        fseek($dbf, $headerLength);

        $index = $this->shapeIndex();
        $fileSize = filesize($this->basePath.'.shp') ?: 0;
        $count = $index !== null ? min($recordCount, count($index)) : $recordCount;

        if ($index === null) {
            fseek($shp, 100);
        }

        for ($record = 0; $record < $count; $record++) {
            $dbfRecord = fread($dbf, $recordLength);
            $attrs = [];
            $offset = 1;

            foreach ($fields as $field) {
                $attrs[$field['name']] = $this->decodeAttribute(trim(substr((string) $dbfRecord, $offset, $field['length'])));
                $offset += $field['length'];
            }

            if ($index !== null) {
                [$recordOffset, $contentLength] = $index[$record];
                if ($recordOffset < 0 || $contentLength < 0 || $recordOffset + 8 + $contentLength > $fileSize) {
                    yield ['attrs' => $attrs, 'geometry' => null];

                    continue;
                }
                fseek($shp, $recordOffset + 8);
                $content = $contentLength > 0 ? fread($shp, $contentLength) : '';
            } else {
                $recordHeader = fread($shp, 8);

                if ($recordHeader === false || strlen($recordHeader) < 8) {
                    break;
                }

                $contentLength = unpack('N', substr($recordHeader, 4, 4))[1] * 2;
                if ($contentLength < 0 || $contentLength > $fileSize) {
                    break;
                }
                $content = $contentLength > 0 ? fread($shp, $contentLength) : '';
            }

            yield [
                'attrs' => $attrs,
                'geometry' => $this->parseGeometry((string) $content),
            ];
        }

        fclose($shp);
        fclose($dbf);
    }

    /**
     * @return list<array{0: int, 1: int}>|null
     */
    private function shapeIndex(): ?array
    {
        $path = $this->basePath.'.shx';

        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, 100);
        $index = [];

        while (strlen($record = (string) fread($handle, 8)) === 8) {
            $index[] = [
                unpack('N', substr($record, 0, 4))[1] * 2,
                unpack('N', substr($record, 4, 4))[1] * 2,
            ];
        }

        fclose($handle);

        return $index;
    }

    /**
     * @return list<array{name: string, length: int}>
     */
    private function dbfFields(): array
    {
        $handle = fopen($this->basePath.'.dbf', 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open '.$this->basePath.'.dbf');
        }

        $fields = $this->dbfFieldsFromHandle($handle);
        fclose($handle);

        return $fields;
    }

    /**
     * @param  resource  $handle
     * @return list<array{name: string, length: int}>
     */
    private function dbfFieldsFromHandle($handle): array
    {
        fseek($handle, 32);
        $fields = [];

        while (true) {
            $descriptor = fread($handle, 32);

            if ($descriptor === false || $descriptor === '' || ord($descriptor[0]) === 0x0D) {
                break;
            }

            $fields[] = [
                'name' => rtrim(substr($descriptor, 0, 11), "\0 "),
                'length' => unpack('C', substr($descriptor, 16, 1))[1],
            ];
        }

        return $fields;
    }

    private function parseGeometry(string $content): ?array
    {
        if (strlen($content) < 4) {
            return null;
        }

        $type = unpack('V', substr($content, 0, 4))[1];

        if ($type === 1 || $type === 11) {
            $x = unpack('d', substr($content, 4, 8))[1];
            $y = unpack('d', substr($content, 12, 8))[1];

            return ['type' => 'Point', 'coordinates' => [$x, $y]];
        }

        if ($type === 5 || $type === 15) {
            $numParts = unpack('V', substr($content, 36, 4))[1];
            $numPoints = unpack('V', substr($content, 40, 4))[1];
            $parts = [];

            for ($i = 0; $i < $numParts; $i++) {
                $parts[] = unpack('V', substr($content, 44 + ($i * 4), 4))[1];
            }

            $pointsOffset = 44 + ($numParts * 4);
            $points = [];

            for ($i = 0; $i < $numPoints; $i++) {
                $x = unpack('d', substr($content, $pointsOffset + ($i * 16), 8))[1];
                $y = unpack('d', substr($content, $pointsOffset + ($i * 16) + 8, 8))[1];
                $points[] = [$x, $y];
            }

            $rings = [];

            foreach ($parts as $index => $start) {
                $end = $parts[$index + 1] ?? $numPoints;
                $rings[] = array_slice($points, $start, $end - $start);
            }

            return ['type' => 'Polygon', 'coordinates' => $rings];
        }

        return null;
    }

    private function decodeAttribute(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $encoding = $this->sourceEncoding();

        if (strtoupper($encoding) === 'UTF-8' || strtoupper($encoding) === 'UTF8') {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

        return is_string($converted) ? $converted : $value;
    }

    private function sourceEncoding(): string
    {
        if ($this->encoding !== null) {
            return $this->encoding;
        }

        $cpg = $this->basePath.'.cpg';

        if (is_file($cpg)) {
            $label = strtoupper(trim((string) file_get_contents($cpg)));
            $this->encoding = match ($label) {
                'UTF-8', 'UTF8' => 'UTF-8',
                '1252', 'CP1252', 'WINDOWS-1252', 'ANSI 1252' => 'Windows-1252',
                'LATIN1', 'ISO-8859-1', 'ISO8859-1' => 'ISO-8859-1',
                default => $label !== '' ? $label : 'Windows-1252',
            };

            return $this->encoding;
        }

        return $this->encoding = 'Windows-1252';
    }
}
