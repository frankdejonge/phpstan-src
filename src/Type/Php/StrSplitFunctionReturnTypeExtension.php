<?php declare(strict_types = 1);

namespace PHPStan\Type\Php;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;

final class StrSplitFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{

	/** @var string[] */
	private array $supportedEncodings;

	public function __construct()
	{
		$supportedEncodings = [];
		if (function_exists('mb_list_encodings')) {
			foreach (mb_list_encodings() as $encoding) {
				$aliases = mb_encoding_aliases($encoding);
				if ($aliases === false) {
					throw new \PHPStan\ShouldNotHappenException();
				}
				$supportedEncodings = array_merge($supportedEncodings, $aliases, [$encoding]);
			}
		}
		$this->supportedEncodings = array_map('strtoupper', $supportedEncodings);
	}

	public function isFunctionSupported(FunctionReflection $functionReflection): bool
	{
		return in_array($functionReflection->getName(), ['str_split', 'mb_str_split'], true);
	}

	public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
	{
		$defaultReturnType = ParametersAcceptorSelector::selectSingle($functionReflection->getVariants())->getReturnType();

		if (count($functionCall->args) < 1) {
			return $defaultReturnType;
		}

		if (count($functionCall->args) >= 2) {
			$splitLengthType = $scope->getType($functionCall->args[1]->value);
			if ($splitLengthType instanceof ConstantIntegerType) {
				$splitLength = $splitLengthType->getValue();
				if ($splitLength < 1) {
					return new ConstantBooleanType(false);
				}
			}
		} else {
			$splitLength = 1;
		}

		if ($functionReflection->getName() === 'mb_str_split') {
			if (count($functionCall->args) >= 3) {
				$strings = TypeUtils::getConstantStrings($scope->getType($functionCall->args[2]->value));
				$values = array_unique(array_map(static function (ConstantStringType $encoding): string {
					return $encoding->getValue();
				}, $strings));

				if (count($values) !== 1) {
					return $defaultReturnType;
				}

				$encoding = $values[0];
				if (!$this->isSupportedEncoding($encoding)) {
					return new ConstantBooleanType(false);
				}
			} else {
				$encoding = mb_internal_encoding();
			}
		}

		if (!isset($splitLength)) {
			return $defaultReturnType;
		}

		$stringType = $scope->getType($functionCall->args[0]->value);
		if (!$stringType instanceof ConstantStringType) {
			return TypeCombinator::intersect(
				new ArrayType(new IntegerType(), new StringType()),
				new NonEmptyArrayType()
			);
		}
		$stringValue = $stringType->getValue();

		$items = isset($encoding)
			? mb_str_split($stringValue, $splitLength, $encoding)
			: str_split($stringValue, $splitLength);
		if (!is_array($items)) {
			throw new \PHPStan\ShouldNotHappenException();
		}

		return self::createConstantArrayFrom($items, $scope);
	}

	private function isSupportedEncoding(string $encoding): bool
	{
		return in_array(strtoupper($encoding), $this->supportedEncodings, true);
	}

	/**
	 * @param string[] $constantArray
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return \PHPStan\Type\Constant\ConstantArrayType
	 */
	private static function createConstantArrayFrom(array $constantArray, Scope $scope): ConstantArrayType
	{
		$keyTypes = [];
		$valueTypes = [];
		$isList = true;
		$i = 0;

		foreach ($constantArray as $key => $value) {
			$keyType = $scope->getTypeFromValue($key);
			if (!$keyType instanceof ConstantIntegerType) {
				throw new \PHPStan\ShouldNotHappenException();
			}
			$keyTypes[] = $keyType;

			$valueTypes[] = $scope->getTypeFromValue($value);

			$isList = $isList && $key === $i;
			$i++;
		}

		return new ConstantArrayType($keyTypes, $valueTypes, $isList ? $i : 0);
	}

}
