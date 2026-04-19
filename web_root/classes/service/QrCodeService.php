<?php
declare(strict_types=1);

final class QrCodeService
{
    private const QUIET_ZONE = 4;
    private const AUTO_TARGET_PIXEL_SIZE = 330;
    private const DEFAULT_MODULE_SIZE = 10;
    private const DEFAULT_FORMAT = 'svg';
    private const DEFAULT_ERROR_CORRECTION_LEVEL = 'M';
    private const PENALTY_N1 = 3;
    private const PENALTY_N2 = 3;
    private const PENALTY_N3 = 40;
    private const PENALTY_N4 = 10;

    private array $qrDebug = [];

    /**
     * @var array<string, array{ordinal:int, format_bits:int}>
     */
    private const ERROR_CORRECTION_LEVELS = [
        'L' => ['ordinal' => 0, 'format_bits' => 1],
        'M' => ['ordinal' => 1, 'format_bits' => 0],
        'Q' => ['ordinal' => 2, 'format_bits' => 3],
        'H' => ['ordinal' => 3, 'format_bits' => 2],
    ];

    /**
     * @var array<int, array{
     *     remainder_bits:int,
     *     alignment_centres:list<int>
     * }>
     */
    private const VERSION_LAYOUTS = [
        1 => [
            'remainder_bits' => 0,
            'alignment_centres' => [],
        ],
        2 => [
            'remainder_bits' => 7,
            'alignment_centres' => [6, 18],
        ],
        3 => [
            'remainder_bits' => 7,
            'alignment_centres' => [6, 22],
        ],
        4 => [
            'remainder_bits' => 7,
            'alignment_centres' => [6, 26],
        ],
        5 => [
            'remainder_bits' => 7,
            'alignment_centres' => [6, 30],
        ],
        6 => [
            'remainder_bits' => 7,
            'alignment_centres' => [6, 34],
        ],
        7 => [
            'remainder_bits' => 0,
            'alignment_centres' => [6, 22, 38],
        ],
        8 => [
            'remainder_bits' => 0,
            'alignment_centres' => [6, 24, 42],
        ],
        9 => [
            'remainder_bits' => 0,
            'alignment_centres' => [6, 26, 46],
        ],
        10 => [
            'remainder_bits' => 0,
            'alignment_centres' => [6, 28, 50],
        ],
    ];

    /**
     * QR spec table, indexed by error-correction ordinal then version.
     *
     * @var array<int, array<int, int>>
     */
    private const ECC_CODEWORDS_PER_BLOCK = [
        0 => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18],
        1 => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26],
        2 => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24],
        3 => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28],
    ];

    /**
     * QR spec table, indexed by error-correction ordinal then version.
     *
     * @var array<int, array<int, int>>
     */
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        0 => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4],
        1 => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5],
        2 => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8],
        3 => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8],
    ];

    /** @var array{0: array<int, int>, 1: array<int, int>}|null */
    private static ?array $gfTables = null;

    /**
     * @param array{
     *     format?:string,
     *     output_format?:string,
     *     module_size?:int|string,
     *     moduleSize?:int|string,
     *     error_correction_level?:string,
     *     errorCorrectionLevel?:string,
     *     auto?:bool
     * } $options
     */
    public function generateQRcode(string $value, array $options = []): string
    {
        $config = $this->resolveRenderConfig($value, $options);
        $modules = $this->buildQrModules($value, $config['error_correction_level']);

        return $config['format'] === 'png'
            ? $this->renderPngBinary($modules, $config['module_size'])
            : $this->renderSvg($modules, $config['module_size']);
    }

    /**
     * @param array{
     *     module_size?:int|string,
     *     moduleSize?:int|string,
     *     error_correction_level?:string,
     *     errorCorrectionLevel?:string,
     *     auto?:bool
     * } $options
     */
    public function generateSvg(string $value, array $options = []): string
    {
        $options['format'] = 'svg';

        return $this->generateQRcode($value, $options);
    }

    /**
     * @param array{
     *     module_size?:int|string,
     *     moduleSize?:int|string,
     *     error_correction_level?:string,
     *     errorCorrectionLevel?:string,
     *     auto?:bool
     * } $options
     */
    public function generatePng(string $value, array $options = []): string
    {
        $options['format'] = 'png';

        return $this->generateQRcode($value, $options);
    }

    /**
     * @param array{
     *     format?:string,
     *     output_format?:string,
     *     module_size?:int|string,
     *     moduleSize?:int|string,
     *     error_correction_level?:string,
     *     errorCorrectionLevel?:string,
     *     auto?:bool
     * } $options
     * @return array{
     *     format:string,
     *     module_size:int,
     *     error_correction_level:string,
     *     version?:int
     * }
     */
    public function resolveAutoOptions(string $value, array $options = []): array
    {
        $options['auto'] = true;

        return $this->resolveRenderConfig($value, $options);
    }

    /**
     * @return array<int, array<int, bool|null>>
     */
    private function buildQrModules(string $value, string $errorCorrectionLevel): array
    {
        $version = $this->resolveVersion(strlen($value), $errorCorrectionLevel);
        $spec = $this->buildVersionSpec($version, $errorCorrectionLevel);
        $size = $this->matrixSize($version);

        $this->qrDebug['payload_length'] = strlen($value);
        $this->qrDebug['version'] = $version;
        $this->qrDebug['matrix_size'] = $size;
        $this->qrDebug['error_correction_level'] = $errorCorrectionLevel;
        $this->qrDebug['uses_version_info'] = $version >= 7;
        $this->qrDebug['alignment_centres'] = $spec['alignment_centres'];

        [$modules, $reserved] = $this->createBaseMatrix($version, $size, $spec['alignment_centres']);

        $bits = $this->buildFinalBits($value, $spec);

        $availableCells = 0;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($reserved[$row][$col] === false) {
                    $availableCells++;
                }
            }
        }

        if ($availableCells !== count($bits)) {
            throw new RuntimeException(
                sprintf(
                    'QR placement mismatch: %d data bits but %d available cells for version %d. Debug: %s',
                    count($bits),
                    $availableCells,
                    $version,
                    json_encode($this->qrDebug, JSON_THROW_ON_ERROR)
                )
            );
        }

        $bestModules = null;
        $bestPenalty = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->copyMatrix($modules);
            $this->placeDataBits($candidate, $reserved, $bits, $mask);
            $this->placeFormatInformation($candidate, $mask, $errorCorrectionLevel);
            $this->placeVersionInformation($candidate, $version);
            $penalty = $this->calculatePenalty($candidate);

            if ($bestPenalty === null || $penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestModules = $candidate;
            }
        }

        if ($bestModules === null) {
            throw new RuntimeException('Unable to generate QR code matrix.');
        }

        return $bestModules;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     format:string,
     *     module_size:int,
     *     error_correction_level:string
     * }
     */
    private function normaliseOptions(array $options): array
    {
        $format = strtolower(trim((string)($options['format'] ?? $options['output_format'] ?? self::DEFAULT_FORMAT)));
        if ($format === '') {
            $format = self::DEFAULT_FORMAT;
        }

        if (!in_array($format, ['svg', 'png'], true)) {
            throw new InvalidArgumentException('QR code format must be svg or png.');
        }

        $rawModuleSize = $options['module_size'] ?? $options['moduleSize'] ?? self::DEFAULT_MODULE_SIZE;
        if ($this->isAutoSelection($rawModuleSize)) {
            $moduleSize = self::DEFAULT_MODULE_SIZE;
        } else {
            $moduleSize = (int)$rawModuleSize;
            if ($moduleSize < 1) {
                throw new InvalidArgumentException('QR code module_size must be 1 or greater.');
            }
        }

        $rawErrorCorrectionLevel = $options['error_correction_level'] ?? $options['errorCorrectionLevel'] ?? self::DEFAULT_ERROR_CORRECTION_LEVEL;
        if ($this->isAutoSelection($rawErrorCorrectionLevel)) {
            $errorCorrectionLevel = self::DEFAULT_ERROR_CORRECTION_LEVEL;
        } else {
            $errorCorrectionLevel = strtoupper(trim((string)$rawErrorCorrectionLevel));
            if ($errorCorrectionLevel === '') {
                $errorCorrectionLevel = self::DEFAULT_ERROR_CORRECTION_LEVEL;
            }

            if (!isset(self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel])) {
                throw new InvalidArgumentException('QR code error correction level must be one of L, M, Q, or H.');
            }
        }

        return [
            'format' => $format,
            'module_size' => $moduleSize,
            'error_correction_level' => $errorCorrectionLevel,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     format:string,
     *     module_size:int,
     *     error_correction_level:string,
     *     version?:int
     * }
     */
    private function resolveRenderConfig(string $value, array $options): array
    {
        $config = $this->normaliseOptions($options);
        $auto = ($options['auto'] ?? false) === true;
        $autoErrorCorrection = $auto || $this->isAutoSelection($options['error_correction_level'] ?? $options['errorCorrectionLevel'] ?? null);
        $autoModuleSize = $auto || $this->isAutoSelection($options['module_size'] ?? $options['moduleSize'] ?? null);

        if (!$autoErrorCorrection && !$autoModuleSize) {
            return $config;
        }

        if ($autoErrorCorrection) {
            $config['error_correction_level'] = $this->resolveAutoErrorCorrectionLevel($value);
        }

        $version = $this->resolveVersion(strlen($value), $config['error_correction_level']);

        if ($autoModuleSize) {
            $config['module_size'] = $this->resolveAutoModuleSize($version);
        }

        if ($auto) {
            $config['version'] = $version;
        }

        return $config;
    }

    private function isAutoSelection(mixed $value): bool
    {
        return is_string($value) && strtolower(trim($value)) === 'auto';
    }

    private function resolveAutoErrorCorrectionLevel(string $value): string
    {
        $byteLength = strlen($value);
        $bestVersion = null;
        $bestLevel = null;

        foreach (['L', 'M', 'Q', 'H'] as $level) {
            try {
                $version = $this->resolveVersion($byteLength, $level);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($bestVersion === null || $version < $bestVersion || ($version === $bestVersion && $this->errorCorrectionStrength($level) > $this->errorCorrectionStrength((string)$bestLevel))) {
                $bestVersion = $version;
                $bestLevel = $level;
            }
        }

        if ($bestLevel === null) {
            throw new InvalidArgumentException('Unable to resolve an automatic QR error correction level for this payload.');
        }

        return $bestLevel;
    }

    private function errorCorrectionStrength(string $level): int
    {
        return match ($level) {
            'L' => 1,
            'M' => 2,
            'Q' => 3,
            'H' => 4,
            default => 0,
        };
    }

    private function resolveAutoModuleSize(int $version): int
    {
        $totalModules = $this->matrixSize($version) + (self::QUIET_ZONE * 2);

        return max(1, (int) round(self::AUTO_TARGET_PIXEL_SIZE / $totalModules));
    }

    private function resolveVersion(int $byteLength, string $errorCorrectionLevel): int
    {
        foreach (array_keys(self::VERSION_LAYOUTS) as $version) {
            if ($this->canEncodeByteLength($byteLength, $version, $errorCorrectionLevel)) {
                return $version;
            }
        }

        $maxBytes = $this->maxSupportedByteLength($errorCorrectionLevel);

        throw new InvalidArgumentException(
            sprintf(
                'QR code input is too long. This service currently supports up to %d bytes at error correction level %s.',
                $maxBytes,
                $errorCorrectionLevel
            )
        );
    }

    private function maxSupportedByteLength(string $errorCorrectionLevel): int
    {
        $maxVersion = max(array_keys(self::VERSION_LAYOUTS));
        $maxBytes = 0;

        while ($this->canEncodeByteLength($maxBytes + 1, $maxVersion, $errorCorrectionLevel)) {
            $maxBytes++;
        }

        return $maxBytes;
    }

    private function canEncodeByteLength(int $byteLength, int $version, string $errorCorrectionLevel): bool
    {
        $spec = $this->buildVersionSpec($version, $errorCorrectionLevel);
        $bitsRequired = 4 + $this->characterCountBitLength($version) + ($byteLength * 8);

        return $bitsRequired <= ($spec['data_codewords'] * 8);
    }

    private function characterCountBitLength(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    /**
     * @return array{
     *     version:int,
     *     data_codewords:int,
     *     ec_codewords_per_block:int,
     *     num_blocks:int,
     *     remainder_bits:int,
     *     alignment_centres:list<int>
     * }
     */
    private function buildVersionSpec(int $version, string $errorCorrectionLevel): array
    {
        if (!isset(self::VERSION_LAYOUTS[$version], self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel])) {
            throw new InvalidArgumentException('Unsupported QR code version or error correction level.');
        }

        $ordinal = self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel]['ordinal'];
        $layout = self::VERSION_LAYOUTS[$version];
        $rawCodewords = intdiv($this->numRawDataModules($version), 8);
        $ecCodewordsPerBlock = self::ECC_CODEWORDS_PER_BLOCK[$ordinal][$version];
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$ordinal][$version];

        return [
            'version' => $version,
            'data_codewords' => $rawCodewords - ($ecCodewordsPerBlock * $numBlocks),
            'ec_codewords_per_block' => $ecCodewordsPerBlock,
            'num_blocks' => $numBlocks,
            'remainder_bits' => $layout['remainder_bits'],
            'alignment_centres' => $layout['alignment_centres'],
        ];
    }

    private function numRawDataModules(int $version): int
    {
        if (!isset(self::VERSION_LAYOUTS[$version])) {
            throw new InvalidArgumentException('Unsupported QR code version.');
        }

        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private function matrixSize(int $version): int
    {
        return 17 + ($version * 4);
    }

    /**
     * @param list<int> $alignmentCentres
     * @return array{0: array<int, array<int, bool|null>>, 1: array<int, array<int, bool>>}
     */
    private function createBaseMatrix(int $version, int $size, array $alignmentCentres): array
    {
        $modules = [];
        $reserved = [];
        $this->qrDebug = [];
        $this->qrDebug['initial'] = $this->countReserved($reserved);

        for ($row = 0; $row < $size; $row++) {
            $modules[$row] = array_fill(0, $size, null);
            $reserved[$row] = array_fill(0, $size, false);
        }
        $this->qrDebug['after_finder'] = $this->countReserved($reserved);

        $this->placeFinderPattern($modules, $reserved, 0, 0, $size);
        $this->placeFinderPattern($modules, $reserved, 0, $size - 7, $size);
        $this->placeFinderPattern($modules, $reserved, $size - 7, 0, $size);
        $this->qrDebug['after_finder'] = $this->countReserved($reserved);

        $this->placeTimingPatterns($modules, $reserved, $size);
        $this->qrDebug['after_timing'] = $this->countReserved($reserved);

        $this->placeAlignmentPatterns($modules, $reserved, $alignmentCentres, $size);
        $this->qrDebug['after_alignment'] = $this->countReserved($reserved);

        $this->reserveFormatInformation($modules, $reserved, $size);
        $this->qrDebug['after_format'] = $this->countReserved($reserved);

        $this->reserveVersionInformation($modules, $reserved, $version, $size);
        $this->qrDebug['after_version'] = $this->countReserved($reserved);

        $darkModuleRow = ($version * 4) + 9;
        $modules[$darkModuleRow][8] = true;
        $reserved[$darkModuleRow][8] = true;
        $this->qrDebug['after_dark_module'] = $this->countReserved($reserved);

        return [$modules, $reserved];
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     */
    private function placeFinderPattern(array &$modules, array &$reserved, int $top, int $left, int $size): void
    {
        for ($row = $top - 1; $row <= $top + 7; $row++) {
            for ($col = $left - 1; $col <= $left + 7; $col++) {
                if ($row < 0 || $col < 0 || $row >= $size || $col >= $size) {
                    continue;
                }

                $withinCore = $row >= $top && $row < $top + 7 && $col >= $left && $col < $left + 7;

                if (!$withinCore) {
                    $modules[$row][$col] = false;
                    $reserved[$row][$col] = true;
                    continue;
                }

                $relativeRow = $row - $top;
                $relativeCol = $col - $left;
                $onOuterBorder = $relativeRow === 0 || $relativeRow === 6 || $relativeCol === 0 || $relativeCol === 6;
                $inInnerSquare = $relativeRow >= 2 && $relativeRow <= 4 && $relativeCol >= 2 && $relativeCol <= 4;

                $modules[$row][$col] = $onOuterBorder || $inInnerSquare;
                $reserved[$row][$col] = true;
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     */
    private function placeTimingPatterns(array &$modules, array &$reserved, int $size): void
    {
        for ($index = 8; $index < $size - 8; $index++) {
            $value = $index % 2 === 0;

            if (!$reserved[6][$index]) {
                $modules[6][$index] = $value;
                $reserved[6][$index] = true;
            }

            if (!$reserved[$index][6]) {
                $modules[$index][6] = $value;
                $reserved[$index][6] = true;
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     * @param list<int> $alignmentCentres
     */
    private function placeAlignmentPatterns(array &$modules, array &$reserved, array $alignmentCentres, int $size): void
    {
        
        if ($alignmentCentres === []) {
            return;
        }

        $lastCentre = $alignmentCentres[count($alignmentCentres) - 1];

        foreach ($alignmentCentres as $rowCentre) {
            foreach ($alignmentCentres as $colCentre) {
                if (
                    ($rowCentre === 6 && $colCentre === 6) ||
                    ($rowCentre === 6 && $colCentre === $lastCentre) ||
                    ($rowCentre === $lastCentre && $colCentre === 6)
                ) {
                    continue;
                }

                for ($row = $rowCentre - 2; $row <= $rowCentre + 2; $row++) {
                    for ($col = $colCentre - 2; $col <= $colCentre + 2; $col++) {
                        if ($row < 0 || $col < 0 || $row >= $size || $col >= $size) {
                            continue;
                        }

                        $distance = max(abs($row - $rowCentre), abs($col - $colCentre));
                        $modules[$row][$col] = $distance === 2 || $distance === 0;
                        $reserved[$row][$col] = true;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     */
    private function reserveFormatInformation(array &$modules, array &$reserved, int $size): void
    {
        for ($index = 0; $index <= 5; $index++) {
            $modules[$index][8] = false;
            $reserved[$index][8] = true;
        }

        $modules[7][8] = false;
        $reserved[7][8] = true;
        $modules[8][8] = false;
        $reserved[8][8] = true;
        $modules[8][7] = false;
        $reserved[8][7] = true;

        for ($index = 9; $index < 15; $index++) {
            $modules[8][14 - $index] = false;
            $reserved[8][14 - $index] = true;
        }

        for ($index = 0; $index < 8; $index++) {
            $modules[8][$size - 1 - $index] = false;
            $reserved[8][$size - 1 - $index] = true;
        }

        for ($index = 8; $index < 15; $index++) {
            $modules[$size - 15 + $index][8] = false;
            $reserved[$size - 15 + $index][8] = true;
        }
    }

    /**
     * @return list<int>
     */
    private function buildDataCodewords(string $value, int $dataCodewords, int $version = 1): array
    {
        $bits = [0, 1, 0, 0];
        $bits = array_merge($bits, $this->intToBits(strlen($value), $this->characterCountBitLength($version)));

        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $bits = array_merge($bits, $this->intToBits(ord($value[$index]), 8));
        }

        $capacity = $dataCodewords * 8;
        $terminatorLength = min(4, max(0, $capacity - count($bits)));

        for ($index = 0; $index < $terminatorLength; $index++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];

        for ($offset = 0, $bitCount = count($bits); $offset < $bitCount; $offset += 8) {
            $codewords[] = $this->bitsToInt(array_slice($bits, $offset, 8));
        }

        $padBytes = [0xEC, 0x11];
        $padIndex = 0;

        while (count($codewords) < $dataCodewords) {
            $codewords[] = $padBytes[$padIndex % 2];
            $padIndex++;
        }

        return $codewords;
    }

    /**
     * @param array{
     *     version:int,
     *     data_codewords:int,
     *     ec_codewords_per_block:int,
     *     num_blocks:int,
     *     remainder_bits:int
     * } $spec
     * @return list<int>
     */
    private function buildFinalBits(string $value, array $spec): array
    {
        $dataCodewords = $this->buildDataCodewords($value, $spec['data_codewords'], $spec['version']);
        $blocks = $this->splitDataIntoBlocks(
            $dataCodewords,
            $spec['num_blocks'],
            $spec['ec_codewords_per_block']
        );
        $ecBlocks = [];

        foreach ($blocks as $block) {
            $ecBlocks[] = $this->buildErrorCorrectionCodewords($block, $spec['ec_codewords_per_block']);
        }

        $interleaved = [];
        $maxDataBlockLength = 0;

        foreach ($blocks as $block) {
            $maxDataBlockLength = max($maxDataBlockLength, count($block));
        }

        for ($index = 0; $index < $maxDataBlockLength; $index++) {
            foreach ($blocks as $block) {
                if (array_key_exists($index, $block)) {
                    $interleaved[] = $block[$index];
                }
            }
        }

        for ($index = 0; $index < $spec['ec_codewords_per_block']; $index++) {
            foreach ($ecBlocks as $block) {
                $interleaved[] = $block[$index];
            }
        }

        return $this->codewordsToBits($interleaved, $spec['remainder_bits']);
    }

    /**
     * @param list<int> $dataCodewords
     * @return list<int>
     */
    private function buildErrorCorrectionCodewords(array $dataCodewords, int $ecCodewords): array
    {
        $divisor = $this->buildGeneratorPolynomial($ecCodewords);
        $remainder = array_fill(0, $ecCodewords, 0);

        foreach ($dataCodewords as $value) {
            $factor = $value ^ array_shift($remainder);
            $remainder[] = 0;

            foreach ($divisor as $index => $coefficient) {
                $remainder[$index] ^= $this->gfMultiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    /**
     * @param list<int> $dataCodewords
     * @return list<list<int>>
     */
    private function splitDataIntoBlocks(array $dataCodewords, int $numBlocks, int $ecCodewordsPerBlock): array
    {
        if ($numBlocks <= 0) {
            throw new InvalidArgumentException('QR code block count must be greater than zero.');
        }

        $rawCodewords = count($dataCodewords) + ($ecCodewordsPerBlock * $numBlocks);
        $numShortBlocks = $numBlocks - ($rawCodewords % $numBlocks);
        $shortBlockLength = intdiv($rawCodewords, $numBlocks);
        $blocks = [];
        $offset = 0;

        for ($index = 0; $index < $numBlocks; $index++) {
            $dataLength = $shortBlockLength - $ecCodewordsPerBlock + ($index < $numShortBlocks ? 0 : 1);
            $blocks[] = array_slice($dataCodewords, $offset, $dataLength);
            $offset += $dataLength;
        }

        return $blocks;
    }

    /**
     * @return list<int>
     */
    private function buildGeneratorPolynomial(int $degree): array
    {
        if ($degree < 1) {
            throw new InvalidArgumentException('QR code ECC degree must be at least 1.');
        }

        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($index = 0; $index < $degree; $index++) {
            for ($position = 0; $position < $degree; $position++) {
                $result[$position] = $this->gfMultiply($result[$position], $root);

                if ($position + 1 < $degree) {
                    $result[$position] ^= $result[$position + 1];
                }
            }

            $root = $this->gfMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param list<int> $codewords
     * @return list<int>
     */
    private function codewordsToBits(array $codewords, int $remainderBits): array
    {
        $bits = [];

        foreach ($codewords as $codeword) {
            $bits = array_merge($bits, $this->intToBits($codeword, 8));
        }

        for ($index = 0; $index < $remainderBits; $index++) {
            $bits[] = 0;
        }

        return $bits;
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     * @param list<int> $bits
     */
    private function placeDataBits(array &$modules, array $reserved, array $bits, int $mask): void
    {
        $size = count($modules);
        $bitIndex = 0;
        $direction = -1;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            $row = $direction === -1 ? $size - 1 : 0;

            while ($row >= 0 && $row < $size) {
                for ($offset = 0; $offset < 2; $offset++) {
                    $currentCol = $col - $offset;

                    if ($reserved[$row][$currentCol]) {
                        continue;
                    }

                    $value = ($bits[$bitIndex] ?? 0) === 1;

                    if ($this->maskApplies($mask, $row, $currentCol)) {
                        $value = !$value;
                    }

                    $modules[$row][$currentCol] = $value;
                    $bitIndex++;
                }

                $row += $direction;
            }

            $direction *= -1;
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function placeFormatInformation(array &$modules, int $mask, string $errorCorrectionLevel): void
    {
        $size = count($modules);
        $bits = $this->formatBitsForMask($mask, $errorCorrectionLevel);

        for ($index = 0; $index <= 5; $index++) {
            $modules[$index][8] = $bits[$index];
        }

        $modules[7][8] = $bits[6];
        $modules[8][8] = $bits[7];
        $modules[8][7] = $bits[8];

        for ($index = 9; $index < 15; $index++) {
            $modules[8][14 - $index] = $bits[$index];
        }

        for ($index = 0; $index < 8; $index++) {
            $modules[8][$size - 1 - $index] = $bits[$index];
        }

        for ($index = 8; $index < 15; $index++) {
            $modules[$size - 15 + $index][8] = $bits[$index];
        }

        $modules[$size - 8][8] = true;
    }

    private function maskApplies(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => (($row + $col) % 2) === 0,
            1 => ($row % 2) === 0,
            2 => ($col % 3) === 0,
            3 => (($row + $col) % 3) === 0,
            4 => (((int) floor($row / 2)) + ((int) floor($col / 3))) % 2 === 0,
            5 => ((($row * $col) % 2) + (($row * $col) % 3)) === 0,
            6 => ((((($row * $col) % 2) + (($row * $col) % 3))) % 2) === 0,
            7 => ((((($row + $col) % 2) + (($row * $col) % 3))) % 2) === 0,
            default => false,
        };
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function calculatePenalty(array $modules): int
    {
        return $this->penaltyForRunsAndFinderPatterns($modules)
            + $this->penaltyForBlocks($modules)
            + $this->penaltyForDarkBalance($modules);
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function penaltyForRunsAndFinderPatterns(array $modules): int
    {
        $size = count($modules);
        $penalty = 0;

        for ($row = 0; $row < $size; $row++) {
            $runColor = false;
            $runLength = 0;
            $runHistory = array_fill(0, 7, 0);

            for ($col = 0; $col < $size; $col++) {
                $value = $modules[$row][$col] === true;

                if ($value === $runColor) {
                    $runLength++;

                    if ($runLength === 5) {
                        $penalty += self::PENALTY_N1;
                    } elseif ($runLength > 5) {
                        $penalty++;
                    }

                    continue;
                }

                $this->finderPenaltyAddHistory($runLength, $runHistory, $size);

                if ($runColor === false) {
                    $penalty += $this->finderPenaltyCountPatterns($runHistory, $size) * self::PENALTY_N3;
                }

                $runColor = $value;
                $runLength = 1;
            }

            $penalty += $this->finderPenaltyTerminateAndCount($runColor, $runLength, $runHistory, $size)
                * self::PENALTY_N3;
        }

        for ($col = 0; $col < $size; $col++) {
            $runColor = false;
            $runLength = 0;
            $runHistory = array_fill(0, 7, 0);

            for ($row = 0; $row < $size; $row++) {
                $value = $modules[$row][$col] === true;

                if ($value === $runColor) {
                    $runLength++;

                    if ($runLength === 5) {
                        $penalty += self::PENALTY_N1;
                    } elseif ($runLength > 5) {
                        $penalty++;
                    }

                    continue;
                }

                $this->finderPenaltyAddHistory($runLength, $runHistory, $size);

                if ($runColor === false) {
                    $penalty += $this->finderPenaltyCountPatterns($runHistory, $size) * self::PENALTY_N3;
                }

                $runColor = $value;
                $runLength = 1;
            }

            $penalty += $this->finderPenaltyTerminateAndCount($runColor, $runLength, $runHistory, $size)
                * self::PENALTY_N3;
        }

        return $penalty;
    }

    /**
     * @param array<int, int> $runHistory
     */
    private function finderPenaltyCountPatterns(array $runHistory, int $size): int
    {
        $n = $runHistory[1];

        if ($n <= 0 || $n > $size * 3) {
            return 0;
        }

        $coreMatches =
            $runHistory[2] === $n
            && $runHistory[3] === $n * 3
            && $runHistory[4] === $n
            && $runHistory[5] === $n;

        if (!$coreMatches) {
            return 0;
        }

        $count = 0;

        if ($runHistory[0] >= $n * 4 && $runHistory[6] >= $n) {
            $count++;
        }

        if ($runHistory[6] >= $n * 4 && $runHistory[0] >= $n) {
            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, int> $runHistory
     */
    private function finderPenaltyTerminateAndCount(bool $runColor, int $runLength, array &$runHistory, int $size): int
    {
        if ($runColor) {
            $this->finderPenaltyAddHistory($runLength, $runHistory, $size);
            $runLength = 0;
        }

        $runLength += $size;
        $this->finderPenaltyAddHistory($runLength, $runHistory, $size);

        return $this->finderPenaltyCountPatterns($runHistory, $size);
    }

    /**
     * @param array<int, int> $runHistory
     */
    private function finderPenaltyAddHistory(int $runLength, array &$runHistory, int $size): void
    {
        if ($runHistory[0] === 0) {
            $runLength += $size;
        }

        array_pop($runHistory);
        array_unshift($runHistory, $runLength);
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function penaltyForBlocks(array $modules): int
    {
        $penalty = 0;
        $size = count($modules);

        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $value = $modules[$row][$col];

                if (
                    $value === $modules[$row][$col + 1]
                    && $value === $modules[$row + 1][$col]
                    && $value === $modules[$row + 1][$col + 1]
                ) {
                    $penalty += self::PENALTY_N2;
                }
            }
        }

        return $penalty;
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function penaltyForDarkBalance(array $modules): int
    {
        $darkCount = 0;
        $total = 0;

        foreach ($modules as $row) {
            foreach ($row as $value) {
                $total++;

                if ($value === true) {
                    $darkCount++;
                }
            }
        }

        $k = intdiv(abs($darkCount * 20 - $total * 10) + $total - 1, $total) - 1;

        return max(0, $k) * self::PENALTY_N4;
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function renderSvg(array $modules, int $moduleSize): string
    {
        $pixelDimension = (count($modules) + (self::QUIET_ZONE * 2)) * $moduleSize;
        $pngDataUri = $this->renderPngDataUri($modules, $moduleSize);

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" role="img" aria-label="QR code"><image href="%2$s" x="0" y="0" width="%1$d" height="%1$d" preserveAspectRatio="none"/></svg>',
            $pixelDimension,
            $pngDataUri
        );
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function renderPngDataUri(array $modules, int $moduleSize): string
    {
        return 'data:image/png;base64,' . base64_encode($this->renderPngBinary($modules, $moduleSize));
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function renderPngBinary(array $modules, int $moduleSize): string
    {
        $size = count($modules);
        $dimension = $size + (self::QUIET_ZONE * 2);
        $pixelDimension = $dimension * $moduleSize;
        $image = imagecreatetruecolor($pixelDimension, $pixelDimension);

        if ($image === false) {
            throw new RuntimeException('Unable to create QR code image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($modules[$row][$col] !== true) {
                    continue;
                }

                $x1 = ($col + self::QUIET_ZONE) * $moduleSize;
                $y1 = ($row + self::QUIET_ZONE) * $moduleSize;
                $x2 = $x1 + $moduleSize - 1;
                $y2 = $y1 + $moduleSize - 1;
                imagefilledrectangle($image, $x1, $y1, $x2, $y2, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * @param list<int> $bits
     */
    private function bitsToInt(array $bits): int
    {
        $value = 0;

        foreach ($bits as $bit) {
            $value = ($value << 1) | $bit;
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private function intToBits(int $value, int $width): array
    {
        $bits = [];

        for ($shift = $width - 1; $shift >= 0; $shift--) {
            $bits[] = ($value >> $shift) & 1;
        }

        return $bits;
    }

    /**
     * @return array<int, bool>
     */
    private function formatBitsForMask(int $mask, string $errorCorrectionLevel): array
    {
        if (!isset(self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel])) {
            throw new InvalidArgumentException('Unsupported QR error correction level.');
        }

        $data = (self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel]['format_bits'] << 3) | $mask;
        $remainder = $data;

        for ($index = 0; $index < 10; $index++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $result = [];

        for ($index = 0; $index < 15; $index++) {
            $result[$index] = (($bits >> $index) & 1) === 1;
        }

        return $result;
    }

    private function gfMultiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        [$expTable, $logTable] = $this->gfTables();

        return $expTable[$logTable[$left] + $logTable[$right]];
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function gfTables(): array
    {
        if (self::$gfTables !== null) {
            return self::$gfTables;
        }

        $expTable = array_fill(0, 512, 0);
        $logTable = array_fill(0, 256, 0);
        $value = 1;

        for ($index = 0; $index < 255; $index++) {
            $expTable[$index] = $value;
            $logTable[$value] = $index;
            $value <<= 1;

            if (($value & 0x100) !== 0) {
                $value ^= 0x11D;
            }
        }

        for ($index = 255; $index < 512; $index++) {
            $expTable[$index] = $expTable[$index - 255];
        }

        self::$gfTables = [$expTable, $logTable];

        return self::$gfTables;
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @return array<int, array<int, bool|null>>
     */
    private function copyMatrix(array $modules): array
    {
        $copy = [];

        foreach ($modules as $row => $values) {
            $copy[$row] = $values;
        }

        return $copy;
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     * @param array<int, array<int, bool>> $reserved
     */
    private function reserveVersionInformation(array &$modules, array &$reserved, int $version, int $size): void
    {
        if ($version < 7) {
            return;
        }

        for ($index = 0; $index < 18; $index++) {
            $a = $size - 11 + ($index % 3);
            $b = intdiv($index, 3);

            $modules[$b][$a] = false;
            $reserved[$b][$a] = true;

            $modules[$a][$b] = false;
            $reserved[$a][$b] = true;
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $modules
     */
    private function placeVersionInformation(array &$modules, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $size = count($modules);
        $bits = $this->versionBits($version);

        for ($index = 0; $index < 18; $index++) {
            $bit = (($bits >> $index) & 1) !== 0;
            $a = $size - 11 + ($index % 3);
            $b = intdiv($index, 3);

            $modules[$b][$a] = $bit;
            $modules[$a][$b] = $bit;
        }
    }

    /**
     * @param array<array<int, bool|null>> $modules
     */
    private function versionBits(int $version): int
    {
        if ($version < 7 || $version > 40) {
            throw new InvalidArgumentException('QR code version bits are only defined for versions 7 to 40.');
        }

        $remainder = $version;

        for ($index = 0; $index < 12; $index++) {
            $remainder = ($remainder << 1) ^ (((($remainder >> 11) & 1) !== 0) ? 0x1F25 : 0);
        }

        return ($version << 12) | $remainder;
    }

    private function countReserved(array $reserved): int
    {
        $count = 0;

        foreach ($reserved as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $count++;
                }
            }
        }

        return $count;
    }

}
