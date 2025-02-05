<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Normalizer;

/**
 * Converts between objects and arrays by mapping properties.
 *
 * The normalization process looks for all the object's properties (public and private).
 * The result is a map from property names to property values. Property values
 * are normalized through the serializer.
 *
 * The denormalization first looks at the constructor of the given class to see
 * if any of the parameters have the same name as one of the properties. The
 * constructor is then called with all parameters or an exception is thrown if
 * any required parameters were not present as properties. Then the denormalizer
 * walks through the given map of property names to property values to see if a
 * property with the corresponding name exists. If found, the property gets the value.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class PropertyNormalizer extends AbstractObjectNormalizer
{
    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, string $format = null)
    {
        return parent::supportsNormalization($data, $format) && $this->supports(\get_class($data));
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, string $type, string $format = null)
    {
        return parent::supportsDenormalization($data, $type, $format) && $this->supports($type);
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return __CLASS__ === static::class;
    }

    /**
     * Checks if the given class has any non-static property.
     */
    private function supports(string $class): bool
    {
        $class = new \ReflectionClass($class);

        // We look for at least one non-static property
        do {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic()) {
                    return true;
                }
            }
        } while ($class = $class->getParentClass());

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function isAllowedAttribute($classOrObject, string $attribute, string $format = null, array $context = [])
    {
        if (!parent::isAllowedAttribute($classOrObject, $attribute, $format, $context)) {
            return false;
        }

        try {
            $reflectionProperty = $this->getReflectionProperty($classOrObject, $attribute);
            if ($reflectionProperty->isStatic()) {
                return false;
            }
        } catch (\ReflectionException $reflectionException) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractAttributes(object $object, string $format = null, array $context = [])
    {
        $reflectionObject = new \ReflectionObject($object);
        $attributes = [];
        $propertyValues = !method_exists($object, '__get') ? (array) $object : null;

        do {
            foreach ($reflectionObject->getProperties() as $property) {
                if ((null !== $propertyValues && (
                        ($property->isPublic() && !\array_key_exists($property->name, $propertyValues))
                        || ($property->isProtected() && !\array_key_exists("\0*\0{$property->name}", $propertyValues))
                        || ($property->isPrivate() && !\array_key_exists("\0{$property->class}\0{$property->name}", $propertyValues))
                    ))
                    || !$this->isAllowedAttribute($reflectionObject->getName(), $property->name, $format, $context)
                ) {
                    continue;
                }

                $attributes[] = $property->name;
            }
        } while ($reflectionObject = $reflectionObject->getParentClass());

        return $attributes;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAttributeValue(object $object, string $attribute, string $format = null, array $context = [])
    {
        try {
            $reflectionProperty = $this->getReflectionProperty($object, $attribute);
        } catch (\ReflectionException $reflectionException) {
            return null;
        }

        // Override visibility
        if (!$reflectionProperty->isPublic()) {
            $reflectionProperty->setAccessible(true);
        }

        return $reflectionProperty->getValue($object);
    }

    /**
     * {@inheritdoc}
     */
    protected function setAttributeValue(object $object, string $attribute, $value, string $format = null, array $context = [])
    {
        try {
            $reflectionProperty = $this->getReflectionProperty($object, $attribute);
        } catch (\ReflectionException $reflectionException) {
            return;
        }

        if ($reflectionProperty->isStatic()) {
            return;
        }

        // Override visibility
        if (!$reflectionProperty->isPublic()) {
            $reflectionProperty->setAccessible(true);
        }

        $reflectionProperty->setValue($object, $value);
    }

    /**
     * @param string|object $classOrObject
     *
     * @throws \ReflectionException
     */
    private function getReflectionProperty($classOrObject, string $attribute): \ReflectionProperty
    {
        $reflectionClass = new \ReflectionClass($classOrObject);
        while (true) {
            try {
                return $reflectionClass->getProperty($attribute);
            } catch (\ReflectionException $e) {
                if (!$reflectionClass = $reflectionClass->getParentClass()) {
                    throw $e;
                }
            }
        }
    }
}
