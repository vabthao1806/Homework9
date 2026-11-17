<?php
/**
 * INS3064 - Homework 09: Robust Calculator
 * Student name : Nguyễn Thị Thảo Vân
 * Student ID   : 24070557
 * Date         : 2026-11-17
 */

session_start();                      // must be the very first statement in the file
error_reporting(E_ALL);
ini_set('display_errors', '0');       // never show raw PHP errors to the user

define('LOG_FILE', __DIR__ . '/calculator_errors.log');

if (!isset($_SESSION['history']) || !is_array($_SESSION['history'])) {
    $_SESSION['history'] = [];
}

// ============================================================
// Custom exception classes
// ============================================================
class DivisionByZeroException extends Exception {}
class InvalidOperatorException extends Exception {}
class InvalidInputException extends Exception {}

// ============================================================
// Helper functions
// ============================================================

/** Escape output for safe HTML display. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Remove line breaks and limit length so each error is exactly one log line. */
function cleanForLog(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return substr($value, 0, 100);
}

/** Append one error record to calculator_errors.log. */
function logError(Throwable $error, string $a, string $op, string $b): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = sprintf(
        "[%s] %s: %s | Input: \"%s %s %s\" | IP: %s\n",
        date('Y-m-d H:i:s'),
        get_class($error),
        $error->getMessage(),
        cleanForLog($a),
        cleanForLog($op),
        cleanForLog($b),
        $ip
    );
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Validate and perform the calculation.
 * Returns ['expression' => string, 'display' => string, 'warning' => string|null].
 * Throws one of the custom exceptions (or OverflowException) on any error.
 */
function calculate(string $rawA, string $op, string $rawB): array
{
    // 1) Input checks first
    if ($rawA === '' || $rawB === '') {
        throw new InvalidInputException('Please enter both numbers before calculating.');
    }
    if (!is_numeric($rawA) || !is_numeric($rawB)) {
        throw new InvalidInputException('Both values must be numbers, such as 12, -3.5 or 2e4. Letters and symbols are not allowed.');
    }

    // 2) Operator check
    if (!in_array($op, ['+', '-', '*', '/', '%', '**'], true)) {
        throw new InvalidOperatorException('Please choose one of the operators: +, -, *, /, %, or **.');
    }

    $a = $rawA + 0;   // numeric string -> int or float
    $b = $rawB + 0;

    // 3) Math-specific errors
    switch ($op) {
        case '+':
            $result = $a + $b;
            break;
        case '-':
            $result = $a - $b;
            break;
        case '*':
            $result = $a * $b;
            break;
        case '/':
            if ($b == 0) {
                throw new DivisionByZeroException('You cannot divide by zero. Please enter a non-zero second number.');
            }
            $result = $a / $b;
            break;
        case '%':
            if ($b == 0) {
                throw new DivisionByZeroException('You cannot take a remainder when dividing by zero. Please enter a non-zero second number.');
            }
            $result = fmod($a, $b);
            break;
        case '**':
            if ($a == 0 && $b < 0) {
                throw new DivisionByZeroException('Zero cannot be raised to a negative power (that would be 1 / 0).');
            }
            $result = $a ** $b;
            break;
    }

    // 4) Overflow / invalid-result checks
    if (is_nan($result)) {
        throw new InvalidInputException('The result is not a real number (for example, a negative number raised to a fractional power). Try different numbers.');
    }
    if (is_infinite($result)) {
        throw new OverflowException('The result is too large for PHP to store. Please try smaller numbers.');
    }

    // 5) Formatting
    $warning = null;
    if (abs($result) >= 1e15) {
        $warning = 'The result is very large, so PHP may round some digits.';
    }

    if ($op === '/') {
        $display = number_format((float) $result, 4, '.', '');
    } elseif (fmod($result, 1) == 0 && abs($result) < 1e15) {
        $display = (string) (int) $result;
    } else {
        $display = (string) $result;
    }

    return [
        'expression' => "$rawA $op $rawB",
        'display'    => $display,
        'warning'    => $warning,
    ];
}

/** Return ' selected' when the option matches the current value (sticky form). */
function sel(string $option, string $current): string
{
    return $option === $current ? ' selected' : '';
}

// ============================================================
// Handle form submission (POST-Redirect-GET prevents resubmission on reload)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['clear_history'])) {
        $_SESSION['history'] = [];
        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Calculation history has been cleared.',
            'values'  => ['num1' => '', 'operator' => '', 'num2' => ''],
        ];
    } else {
        $num1     = trim((string) ($_POST['num1'] ?? ''));
        $operator = trim((string) ($_POST['operator'] ?? ''));
        $num2     = trim((string) ($_POST['num2'] ?? ''));
        $values   = ['num1' => $num1, 'operator' => $operator, 'num2' => $num2];
        $flash    = [];

        try {
            $outcome = calculate($num1, $operator, $num2);

            // Most recent calculation goes first
            array_unshift($_SESSION['history'], [
                'expression' => $outcome['expression'],
                'result'     => $outcome['display'],
                'timestamp'  => date('Y-m-d H:i:s'),
            ]);

            $message = 'Result: ' . $outcome['expression'] . ' = ' . $outcome['display'];
            if ($outcome['warning'] !== null) {
                $flash = ['type' => 'warning', 'message' => $message . '. Note: ' . $outcome['warning']];
            } else {
                $flash = ['type' => 'success', 'message' => $message];
            }

        } catch (DivisionByZeroException $ex) {
            logError($ex, $num1, $operator, $num2);
            $flash = ['type' => 'error', 'message' => 'Division by zero: ' . $ex->getMessage()];

        } catch (InvalidOperatorException $ex) {
            logError($ex, $num1, $operator, $num2);
            $flash = ['type' => 'error', 'message' => 'Invalid operator: ' . $ex->getMessage()];

        } catch (InvalidInputException $ex) {
            logError($ex, $num1, $operator, $num2);
            $flash = ['type' => 'error', 'message' => 'Invalid input: ' . $ex->getMessage()];

        } catch (OverflowException $ex) {
            logError($ex, $num1, $operator, $num2);
            $flash = ['type' => 'error', 'message' => 'Overflow: ' . $ex->getMessage()];

        } catch (Throwable $ex) {
            // Any unexpected error: log it, show only a friendly message
            logError($ex, $num1, $operator, $num2);
            $flash = ['type' => 'error', 'message' => 'Something unexpected went wrong. Please try again. If the problem continues, contact your lecturer.'];
        }

        $flash['values'] = $values;
        $_SESSION['flash'] = $flash;
    }

    header('Location: calculator.php');
    exit;
}

// ============================================================
// Render page (GET request)
// ============================================================
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$values  = $flash['values'] ?? ['num1' => '', 'operator' => '', 'num2' => ''];
$history = $_SESSION['history'];
$operators = ['+', '-', '*', '/', '%', '**'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INS3064 - Robust Calculator</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; padding: 20px; }
        .container { max-width: 560px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 20px; }
        h2 { margin-top: 0; font-size: 20px; }
        .card { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1); margin-bottom: 20px; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .row input, .row select { flex: 1 1 120px; padding: 10px; font-size: 16px; border: 1px solid #cbd5e1; border-radius: 6px; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-size: 16px; color: #fff; cursor: pointer; }
        .btn-calc { background: #2563eb; }
        .btn-calc:hover { background: #1d4ed8; }
        .btn-clear { background: #6b7280; }
        .btn-clear:hover { background: #4b5563; }
        .alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 16px; border-left: 5px solid; }
        .alert-success { background: #dcfce7; border-color: #16a34a; color: #14532d; }
        .alert-error   { background: #fee2e2; border-color: #dc2626; color: #7f1d1d; }
        .alert-warning { background: #fef9c3; border-color: #ca8a04; color: #713f12; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; }
        .empty { color: #6b7280; font-style: italic; }
        @media (max-width: 480px) {
            .row input, .row select { flex-basis: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Robust Calculator</h1>

    <div class="card">
        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="calculator.php">
            <div class="row">
                <input type="text" name="num1" placeholder="First number"
                       value="<?= e($values['num1']) ?>" inputmode="decimal" autocomplete="off">

                <select name="operator" aria-label="Operator">
                    <option value="" disabled<?= $values['operator'] === '' ? ' selected' : '' ?>>Operator</option>
                    <?php foreach ($operators as $op): ?>
                        <option value="<?= e($op) ?>"<?= sel($op, $values['operator']) ?>><?= e($op) ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="num2" placeholder="Second number"
                       value="<?= e($values['num2']) ?>" inputmode="decimal" autocomplete="off">
            </div>

            <div class="row">
                <button type="submit" name="calculate" value="1" class="btn btn-calc">Calculate</button>
                <button type="submit" name="clear_history" value="1" class="btn btn-clear" formnovalidate>Clear History</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Calculation History</h2>
        <?php if (empty($history)): ?>
            <p class="empty">No calculations yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>#</th><th>Expression</th><th>Result</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php $number = count($history); ?>
                    <?php foreach ($history as $record): ?>
                        <tr>
                            <td><?= $number-- ?></td>
                            <td><?= e($record['expression']) ?></td>
                            <td><?= e($record['result']) ?></td>
                            <td><?= e($record['timestamp']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
