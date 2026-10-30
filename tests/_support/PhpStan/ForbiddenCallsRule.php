<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Stroj stráži konvencie: čas len cez Clock, žiadny float, bcmath len v Money, Hours a Fx (FxConverter, FxQuote) (D-06, NFR-04).
 *
 * @implements Rule<Expr>
 */
final class ForbiddenCallsRule implements Rule
{
    private const TIME_FUNCTIONS = [
        'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime',
        'date_create', 'date_create_immutable', 'microtime',
        'date_create_from_format', 'date_create_immutable_from_format',
        'getdate', 'localtime', 'idate', 'hrtime', 'gettimeofday',
        'strftime', 'gmstrftime',
    ];
    private const TIME_CLASSES    = ['datetime', 'datetimeimmutable', 'codeigniter\\i18n\\time'];
    private const FLOAT_FUNCTIONS = [
        'floatval', 'doubleval', 'round', 'number_format',
        'floor', 'ceil', 'fdiv', 'fmod', 'pow', 'sqrt',
    ];
    private const FLOAT_CONSTANTS = ['filter_validate_float'];

    /**
     * printf rodina: nazov => index argumentu s formatom.
     */
    private const FORMAT_FUNCTIONS = [
        'sprintf' => 0, 'printf' => 0, 'vsprintf' => 0, 'vprintf' => 0, 'fprintf' => 1, 'vfprintf' => 1,
    ];

    private const BC_FUNCTIONS = [
        'bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcmod', 'bcpow', 'bcpowmod',
        'bcsqrt', 'bccomp', 'bcround', 'bcfloor', 'bcceil', 'bcscale',
    ];
    private const TIME_ALLOWED_FILES = [
        'app/Libraries/Platform/Clock.php',
        'app/Libraries/Platform/FrozenClock.php',
    ];
    private const BC_ALLOWED_FILES = [
        'app/Libraries/Platform/Money.php', 'app/Libraries/Platform/Hours.php',
        'app/Libraries/Fx/FxConverter.php', 'app/Libraries/Fx/FxQuote.php',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = str_replace('\\', '/', $scope->getFile());

        if ($node instanceof Double) {
            return [$this->float('Pretypovanie (float) je zakázané, použi string a Money.')];
        }

        $inMoney = $this->endsWithAny($file, self::BC_ALLOWED_FILES);

        if ($node instanceof Float_) {
            return $inMoney ? [] : [$this->float('Float literál je zakázaný, použi string a Money.')];
        }

        if ($this->isFloatOperator($node)) {
            return $inMoney ? [] : [$this->float('Operátor / a ** môže vrátiť float, použi Money alebo intdiv().')];
        }

        if ($node instanceof ConstFetch
            && in_array(strtolower(ltrim($node->name->toString(), '\\')), self::FLOAT_CONSTANTS, true)) {
            return [$this->float($node->name->toString() . ' vracia float, použi validáciu stringu a Money.')];
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $name = strtolower(ltrim($node->name->toString(), '\\'));

            if (in_array($name, self::TIME_FUNCTIONS, true)) {
                return $this->timeAllowed($file) ? [] : [$this->time($name . '()')];
            }
            if (in_array($name, self::FLOAT_FUNCTIONS, true)) {
                return [$this->float($name . '() je zakázané, použi App\\Libraries\\Platform\\Money.')];
            }
            if ($name === 'settype' && $this->stringArg($node, 1, '/^\s*(float|double)\s*$/i')) {
                return [$this->float('settype(..., \'float\') je zakázané, použi string a Money.')];
            }
            if (isset(self::FORMAT_FUNCTIONS[$name])
                && $this->stringArg($node, self::FORMAT_FUNCTIONS[$name], '/%(?:\d+\$)?[-+ 0\'#]*(?:\'.)?\d*(?:\.\d+)?[eEfFgGhH]/')) {
                return [$this->float($name . '() s formátom %f/%e/%g je zakázané, použi Money.')];
            }
            if (in_array($name, self::BC_FUNCTIONS, true)) {
                return $this->endsWithAny($file, self::BC_ALLOWED_FILES) ? [] : [
                    RuleErrorBuilder::message($name . '() je povolené len v Money, Hours a Fx.')
                        ->identifier('app.forbiddenBcmath')->build(),
                ];
            }

            return [];
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $class = strtolower($scope->resolveName($node->class));
            if (in_array($class, self::TIME_CLASSES, true) && ! $this->timeAllowed($file)) {
                return [$this->time('new ' . $node->class->toString())];
            }

            return [];
        }

        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $class  = strtolower($scope->resolveName($node->class));
            $method = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
            // Vsetky staticke metody DateTime* su konstruktory (createFromFormat, createFromInterface, ...).
            $bad = $class === 'codeigniter\\i18n\\time' || in_array($class, ['datetime', 'datetimeimmutable'], true);
            if ($bad && ! $this->timeAllowed($file)) {
                return [$this->time($node->class->toString() . '::' . $method . '()')];
            }
        }

        return [];
    }

    private function time(string $what): IdentifierRuleError
    {
        return RuleErrorBuilder::message($what . ' je zakázané mimo Clock, použi service(\'clock\').')
            ->identifier('app.forbiddenTime')->build();
    }

    private function float(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('app.forbiddenFloat')->build();
    }

    private function isFloatOperator(Node $node): bool
    {
        foreach ([BinaryOp\Div::class, AssignOp\Div::class, BinaryOp\Pow::class, AssignOp\Pow::class] as $class) {
            if ($node instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function stringArg(FuncCall $node, int $index, string $pattern): bool
    {
        $arg = $node->getArgs()[$index] ?? null;

        return $arg !== null && $arg->value instanceof String_ && preg_match($pattern, $arg->value->value) === 1;
    }

    private function timeAllowed(string $file): bool
    {
        return $this->endsWithAny($file, self::TIME_ALLOWED_FILES);
    }

    /**
     * @param list<string> $suffixes
     */
    private function endsWithAny(string $file, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
