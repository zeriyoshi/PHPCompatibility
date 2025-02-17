<?php
/**
 * PHPCompatibility, an external standard for PHP_CodeSniffer.
 *
 * @package   PHPCompatibility
 * @copyright 2012-2020 PHPCompatibility Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCompatibility/PHPCompatibility
 */

namespace PHPCompatibility\Sniffs\FunctionDeclarations;

use PHPCompatibility\Sniff;
use PHPCompatibility\Helpers\ComplexVersionNewFeatureTrait;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHPCSUtils\BackCompat\BCTokens;
use PHPCSUtils\Tokens\Collections;
use PHPCSUtils\Utils\Conditions;
use PHPCSUtils\Utils\FunctionDeclarations;
use PHPCSUtils\Utils\Scopes;

/**
 * Detect and verify the use of parameter type declarations in function declarations.
 *
 * Parameter type declarations - class/interface names only - is available since PHP 5.0.
 * - Since PHP 5.1, the `array` keyword can be used.
 * - Since PHP 5.2, `self` and `parent` can be used. Previously, those were interpreted as
 *   class names.
 * - Since PHP 5.4, the `callable` keyword.
 * - Since PHP 7.0, scalar type declarations are available.
 * - Since PHP 7.1, the `iterable` pseudo-type is available.
 * - Since PHP 7.2, the generic `object` type is available.
 * - Since PHP 8.0, the `mixed` pseudo-type is available.
 *
 * Additionally, this sniff does a cursory check for typical invalid type declarations,
 * such as:
 * - `boolean` (should be `bool`), `integer` (should be `int`) and `static`.
 * - `self`/`parent` as type declaration used outside class context throws a fatal error since PHP 7.0.
 * - nullable `mixed` type declarations.
 *
 * PHP version 5.0+
 *
 * @link https://www.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration
 * @link https://wiki.php.net/rfc/callable
 * @link https://wiki.php.net/rfc/scalar_type_hints_v5
 * @link https://wiki.php.net/rfc/iterable
 * @link https://wiki.php.net/rfc/object-typehint
 * @link https://wiki.php.net/rfc/mixed_type_v2
 *
 * @since 7.0.0
 * @since 7.1.0  Now extends the `AbstractNewFeatureSniff` instead of the base `Sniff` class.
 * @since 9.0.0  Renamed from `NewScalarTypeDeclarationsSniff` to `NewParamTypeDeclarationsSniff`.
 * @since 10.0.0 Now extends the base `Sniff` class and uses the `ComplexVersionNewFeatureTrait`.
 */
class NewParamTypeDeclarationsSniff extends Sniff
{
    use ComplexVersionNewFeatureTrait;

    /**
     * A list of new types.
     *
     * The array lists : version number with false (not present) or true (present).
     * If's sufficient to list the first version where the keyword appears.
     *
     * @since 7.0.0
     * @since 7.0.3 Now lists all param type declarations, not just the PHP 7+ scalar ones.
     *
     * @var array(string => array(string => bool))
     */
    protected $newTypes = [
        'array' => [
            '5.0' => false,
            '5.1' => true,
        ],
        'self' => [
            '5.1' => false,
            '5.2' => true,
        ],
        'parent' => [
            '5.1' => false,
            '5.2' => true,
        ],
        'callable' => [
            '5.3' => false,
            '5.4' => true,
        ],
        'int' => [
            '5.6' => false,
            '7.0' => true,
        ],
        'float' => [
            '5.6' => false,
            '7.0' => true,
        ],
        'bool' => [
            '5.6' => false,
            '7.0' => true,
        ],
        'string' => [
            '5.6' => false,
            '7.0' => true,
        ],
        'iterable' => [
            '7.0' => false,
            '7.1' => true,
        ],
        'object' => [
            '7.1' => false,
            '7.2' => true,
        ],
        'mixed' => [
            '7.4' => false,
            '8.0' => true,
        ],
    ];

    /**
     * Invalid types
     *
     * The array lists : the invalid type hint => what was probably intended/alternative.
     *
     * @since 7.0.3
     *
     * @var array(string => string)
     */
    protected $invalidTypes = [
        'static'  => 'self',
        'boolean' => 'bool',
        'integer' => 'int',
    ];


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @since 7.0.0
     * @since 7.1.3  Now also checks closures.
     * @since 10.0.0 Now also checks PHP 7.4+ arrow functions.
     *
     * @return array
     */
    public function register()
    {
        return Collections::functionDeclarationTokens();
    }


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @since 7.0.0
     * @since 7.0.3 - Added check for non-scalar type declarations.
     *              - Added check for invalid type declarations.
     *              - Added check for usage of `self` type declaration outside
     *                class scope.
     * @since 8.2.0 Added check for `parent` type declaration outside class scope.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in
     *                                               the stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        // Get all parameters from method signature.
        try {
            $paramNames = FunctionDeclarations::getParameters($phpcsFile, $stackPtr);
        } catch (RuntimeException $e) {
            // Most likely a T_STRING which wasn't an arrow function.
            return;
        }

        if (empty($paramNames)) {
            return;
        }

        $supportsPHP4 = $this->supportsBelow('4.4');
        $tokens       = $phpcsFile->getTokens();

        foreach ($paramNames as $param) {
            if ($param['type_hint'] === '') {
                continue;
            }

            // Strip off potential nullable indication.
            $typeHint = \ltrim($param['type_hint'], '?');
            $typeHint = \strtolower($typeHint);

            if ($supportsPHP4 === true) {
                $phpcsFile->addError(
                    'Type declarations were not present in PHP 4.4 or earlier.',
                    $param['token'],
                    'TypeHintFound'
                );
            } elseif (isset($this->newTypes[$typeHint])) {
                $itemInfo = [
                    'name' => $typeHint,
                ];
                $this->handleFeature($phpcsFile, $param['token'], $itemInfo);

                // As of PHP 7.0, using `self` or `parent` outside class scope throws a fatal error.
                // Only throw this error for PHP 5.2+ as before that the "type hint not supported" error
                // will be thrown.
                if (($typeHint === 'self' || $typeHint === 'parent')
                    && (Conditions::hasCondition($phpcsFile, $stackPtr, BCTokens::ooScopeTokens()) === false
                        || ($tokens[$stackPtr]['code'] === \T_FUNCTION
                        && Scopes::isOOMethod($phpcsFile, $stackPtr) === false))
                    && $this->supportsBelow('5.1') === false
                ) {
                    $phpcsFile->addError(
                        "'%s' type cannot be used outside of class scope",
                        $param['token'],
                        \ucfirst($typeHint) . 'OutsideClassScopeFound',
                        [$typeHint]
                    );
                }

                /*
                 * Nullable mixed type declarations are not allowed, but could have been used prior
                 * to PHP 8 if the type hint referred to a class named "Mixed".
                 * Only throw an error if PHP 8+ needs to be supported.
                 */
                if (($typeHint === 'mixed' && $param['nullable_type'] === true)
                    && $this->supportsAbove('8.0') === true
                ) {
                    $phpcsFile->addError(
                        'Mixed types cannot be nullable, null is already part of the mixed type',
                        $param['token'],
                        'NullableMixed'
                    );
                }
            } elseif (isset($this->invalidTypes[$typeHint])) {
                $error = "'%s' is not a valid type declaration. Did you mean %s ?";
                $data  = [
                    $typeHint,
                    $this->invalidTypes[$typeHint],
                ];

                $phpcsFile->addError($error, $param['token'], 'InvalidTypeHintFound', $data);
            }
        }
    }


    /**
     * Handle the retrieval of relevant information and - if necessary - throwing of an
     * error for a matched item.
     *
     * @since 10.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the relevant token in
     *                                               the stack.
     * @param array                       $itemInfo  Base information about the item.
     *
     * @return void
     */
    protected function handleFeature(File $phpcsFile, $stackPtr, array $itemInfo)
    {
        $itemArray   = $this->newTypes[$itemInfo['name']];
        $versionInfo = $this->getVersionInfo($itemArray);

        if (empty($versionInfo['not_in_version'])
            || $this->supportsBelow($versionInfo['not_in_version']) === false
        ) {
            return;
        }

        $this->addError($phpcsFile, $stackPtr, $itemInfo, $versionInfo);
    }


    /**
     * Generates the error for this item.
     *
     * @since 10.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile   The file being scanned.
     * @param int                         $stackPtr    The position of the relevant token in
     *                                                 the stack.
     * @param array                       $itemInfo    Base information about the item.
     * @param string[]                    $versionInfo Array with detail (version) information
     *                                                 relevant to the item.
     *
     * @return void
     */
    protected function addError(File $phpcsFile, $stackPtr, array $itemInfo, array $versionInfo)
    {
        // Overrule the default message template.
        $this->msgTemplate = "'%s' type declaration is not present in PHP version %s or earlier";

        $msgInfo = $this->getMessageInfo($itemInfo['name'], $itemInfo['name'], $versionInfo);

        $phpcsFile->addError($msgInfo['message'], $stackPtr, $msgInfo['errorcode'], $msgInfo['data']);
    }
}
