<?php
namespace Vanilla;

// png written a row at a time so big pictures never sit in memory
final class PngStream {
    private $w;
    private $h;
    private $z = null;
    private $raw = '';
    private $idat = '';
    private $n = 0;

    function __construct(int $w, int $h) {
        $this->w = $w;
        $this->h = $h;
        if (function_exists('deflate_init')) {
            $this->z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => $w * $h > 1000000 ? 6 : 9]);
        }
    }

    function row(string $rgba): void {
        $rl = $this->w * 4;
        $buf = '';
        for ($o = 0, $L = strlen($rgba); $o < $L; $o += $rl) {
            $buf .= "\0" . substr($rgba, $o, $rl);
            $this->n++;
        }
        if ($this->z) {
            $this->idat .= deflate_add($this->z, $buf, ZLIB_NO_FLUSH);
        } else {
            $this->raw .= $buf;
        }
    }

    function finish(): string {
        $data = $this->z ? $this->idat . deflate_add($this->z, '', ZLIB_FINISH) : gzcompress($this->raw, 9);
        $chunk = function ($t, $d) {
            return pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        };
        return "\x89PNG\r\n\x1a\n" . $chunk(
            'IHDR',
            pack('NNCCCCC', $this->w, $this->h, 8, 6, 0, 0, 0)
        ) . $chunk('IDAT', $data) . $chunk('IEND', '');
    }

}
