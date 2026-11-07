<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$child = new ReflectionClass(Registrar_Adapter_Enom::class);
$parent = new ReflectionClass(Registrar_AdapterAbstract::class);
$failed = false;

foreach ($parent->getMethods() as $parentMethod) {
    $name = $parentMethod->getName();
    $childMethod = $child->getMethod($name);
    if ($childMethod->getDeclaringClass()->getName() !== Registrar_Adapter_Enom::class) {
        continue;
    }
    try {
        $prototype = $childMethod->getPrototype();
    } catch (ReflectionException $e) {
        fwrite(STDERR, $name . ' is not compatible: ' . $e->getMessage() . "\n");
        $failed = true;
        continue;
    }
    $childReturn = $childMethod->getReturnType();
    $parentReturn = $prototype->getReturnType();
    echo $name
        . ' child=' . ($childReturn === null ? 'none' : (string) $childReturn)
        . ' parent=' . ($parentReturn === null ? 'none' : (string) $parentReturn)
        . ' static=' . ($childMethod->isStatic() ? 'yes' : 'no')
        . ' line=' . $childMethod->getStartLine()
        . ' core=' . $parentMethod->getStartLine()
        . "\n";
}

$http = $child->getMethod('getHttpClient')->getReturnType();
if ((string) $http !== Symfony\Contracts\HttpClient\HttpClientInterface::class) {
    fwrite(STDERR, "getHttpClient return type is not the 0.8.9 contract\n");
    $failed = true;
}

echo $failed ? "adapter_loaded=no\n" : "adapter_loaded=yes\n";
exit($failed ? 1 : 0);
