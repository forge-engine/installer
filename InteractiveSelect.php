<?php

declare(strict_types=1);

final class InteractiveSelect
{
    private bool $initialRender = true;
    private const VISIBLE_ITEMS = 7;

    public function select(array $options, string $prompt = 'Select an option', ?int $defaultIndex = null): ?int
    {
        if (empty($options)) {
            return null;
        }

        $selectedIndex = $defaultIndex ?? 0;
        $startIndex = 0;

        if (!$this->isInteractive()) {
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }

        $this->hideCursor();

        try {
            $this->setRawMode();

            echo "\033[1;36m{$prompt}:\033[0m\n";
            echo "Use \xE2\x86\x91\xE2\x86\x93 to navigate, Enter to select, Esc to cancel\n\n";

            $this->initialRender = true;

            while (true) {
                $startIndex = $this->calculateStartIndex($selectedIndex, count($options));
                $this->renderMenu($options, $selectedIndex, $startIndex);
                $this->initialRender = false;

                $key = $this->readKey();

                if ($key === 'up' && $selectedIndex > 0) {
                    $selectedIndex--;
                } elseif ($key === 'down' && $selectedIndex < count($options) - 1) {
                    $selectedIndex++;
                } elseif ($key === 'enter') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return $selectedIndex;
                } elseif ($key === 'escape') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return null;
                }
            }
        } catch (\Exception $e) {
            $this->showCursor();
            $this->restoreMode();
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }
    }

    public function multiSelect(array $options, string $prompt = 'Select options', array $defaultSelected = []): ?array
    {
        if (empty($options)) {
            return null;
        }

        $selectedIndices = array_flip($defaultSelected);
        $currentIndex = 0;
        $startIndex = 0;

        if (!$this->isInteractive()) {
            return $this->fallbackMultiSelection($options, $prompt, $defaultSelected);
        }

        $this->hideCursor();

        try {
            $this->setRawMode();

            echo "\033[1;36m{$prompt}:\033[0m\n";
            echo "Use \xE2\x86\x91\xE2\x86\x93 to navigate, Space to toggle, Enter to confirm, Esc to cancel\n\n";

            $this->initialRender = true;

            while (true) {
                $startIndex = $this->calculateStartIndex($currentIndex, count($options));
                $this->renderMultiMenu($options, $currentIndex, $startIndex, $selectedIndices);
                $this->initialRender = false;

                $key = $this->readKey();

                if ($key === 'up' && $currentIndex > 0) {
                    $currentIndex--;
                } elseif ($key === 'down' && $currentIndex < count($options) - 1) {
                    $currentIndex++;
                } elseif ($key === 'space') {
                    if (isset($selectedIndices[$currentIndex])) {
                        unset($selectedIndices[$currentIndex]);
                    } else {
                        $selectedIndices[$currentIndex] = true;
                    }
                } elseif ($key === 'enter') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    $result = array_keys($selectedIndices);
                    sort($result);
                    return empty($result) ? null : $result;
                } elseif ($key === 'escape') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return null;
                }
            }
        } catch (\Exception $e) {
            $this->showCursor();
            $this->restoreMode();
            return $this->fallbackMultiSelection($options, $prompt, $defaultSelected);
        }
    }

    private function calculateStartIndex(int $selectedIndex, int $totalOptions): int
    {
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);

        if ($selectedIndex < $visibleCount) {
            return 0;
        }

        if ($selectedIndex >= $totalOptions - $visibleCount) {
            return max(0, $totalOptions - $visibleCount);
        }

        return $selectedIndex - (int) floor($visibleCount / 2);
    }

    private function renderMenu(array $options, int $selectedIndex, int $startIndex): void
    {
        $totalOptions = count($options);
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);
        $endIndex = min($startIndex + $visibleCount, $totalOptions);

        if (!$this->initialRender) {
            echo "\033[{$visibleCount}A";
        }

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $line = rtrim((string) ($options[$i] ?? ''));
            if ($i === $selectedIndex) {
                echo "\033[K\033[1;32m> {$line}\033[0m\n";
            } else {
                echo "\033[K  {$line}\033[0m\n";
            }
        }
    }

    private function renderMultiMenu(array $options, int $currentIndex, int $startIndex, array $selectedIndices): void
    {
        $totalOptions = count($options);
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);
        $endIndex = min($startIndex + $visibleCount, $totalOptions);

        if (!$this->initialRender) {
            echo "\033[{$visibleCount}A";
        }

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $line = rtrim((string) ($options[$i] ?? ''));
            $isSelected = isset($selectedIndices[$i]);
            $checkbox = $isSelected ? '[X]' : '[ ]';

            if ($i === $currentIndex) {
                echo "\033[K\033[1;32m> {$checkbox} {$line}\033[0m\n";
            } else {
                echo "\033[K  {$checkbox} {$line}\033[0m\n";
            }
        }
    }

    private function readKey(): ?string
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        $result = @stream_select($read, $write, $except, 0, 200000);
        if ($result === false || $result === 0) {
            return null;
        }

        if (empty($read)) {
            return null;
        }

        $char = fread(STDIN, 1);
        if ($char === false || $char === '') {
            return null;
        }

        if ($char === "\033") {
            $char2 = fread(STDIN, 1);
            if ($char2 === false || $char2 === '') {
                return 'escape';
            }
            if ($char2 === '[') {
                $char3 = fread(STDIN, 1);
                if ($char3 === false || $char3 === '') {
                    return null;
                }
                if ($char3 === 'A') {
                    return 'up';
                } elseif ($char3 === 'B') {
                    return 'down';
                } elseif ($char3 === 'C') {
                    return 'right';
                } elseif ($char3 === 'D') {
                    return 'left';
                }
            }
        } elseif ($char === "\n" || $char === "\r") {
            return 'enter';
        } elseif ($char === ' ') {
            return 'space';
        }

        return null;
    }

    private function setRawMode(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        @system('stty -icanon -echo 2>/dev/null');
    }

    private function restoreMode(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        @system('stty icanon echo 2>/dev/null');
    }

    private function hideCursor(): void
    {
        echo "\033[?25l";
    }

    private function showCursor(): void
    {
        echo "\033[?25h";
    }

    private function isInteractive(): bool
    {
        if (!function_exists('posix_isatty')) {
            return false;
        }
        return @posix_isatty(STDIN);
    }

    private function fallbackSelection(array $options, string $prompt, ?int $defaultIndex): ?int
    {
        echo "\033[1;36m{$prompt}:\033[0m\n\n";

        foreach ($options as $index => $option) {
            $number = $index + 1;
            echo "[{$number}] {$option}\n";
        }

        echo "\n";
        $defaultLabel = $defaultIndex !== null ? ' [default: ' . ($defaultIndex + 1) . ']' : '';
        $input = readline("Enter number (1-" . count($options) . "){$defaultLabel}: ");

        if ($input === '' && $defaultIndex !== null) {
            return $defaultIndex;
        }

        if (is_numeric($input)) {
            $selected = (int) $input - 1;
            if ($selected >= 0 && $selected < count($options)) {
                return $selected;
            }
        }

        return $defaultIndex;
    }

    private function fallbackMultiSelection(array $options, string $prompt, array $defaultSelected): ?array
    {
        echo "\033[1;36m{$prompt}:\033[0m\n\n";

        foreach ($options as $index => $option) {
            $default = in_array($index, $defaultSelected, true) ? ' (default)' : '';
            echo '[' . ($index + 1) . "] {$option}{$default}\n";
        }

        echo "\n";
        $defaultHint = !empty($defaultSelected) ? ' [default: ' . implode(',', array_map(fn($i) => $i + 1, $defaultSelected)) . ']' : '';
        $input = readline("Enter numbers separated by commas (e.g., 1,3,5){$defaultHint}: ");

        if (empty($input)) {
            return empty($defaultSelected) ? null : $defaultSelected;
        }

        $selected = [];
        $numbers = explode(',', $input);
        foreach ($numbers as $num) {
            $num = trim($num);
            if (is_numeric($num)) {
                $idx = (int) $num - 1;
                if ($idx >= 0 && $idx < count($options)) {
                    $selected[] = $idx;
                }
            }
        }

        return empty($selected) ? null : $selected;
    }
}
