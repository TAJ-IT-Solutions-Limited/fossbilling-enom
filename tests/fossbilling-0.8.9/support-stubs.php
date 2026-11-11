<?php

declare(strict_types=1);

/**
 * Test doubles for classes that FOSSBilling 0.8.9 resolves while loading
 * Registrar_AdapterAbstract, but that are not part of the registrar contract
 * under test. The real FOSSBilling\Exception reads Config and DEBUG during
 * construction, so this file keeps the constructor signature only.
 *
 * Source of the constructor: https://github.com/FOSSBilling/FOSSBilling/blob/0.8.9/src/library/FOSSBilling/Exception.php
 * Commit: e594f8a5f91ea4cc905708f8dc1e42ccb752ed21
 */

namespace FOSSBilling {
    class Exception extends \Exception
    {
        public function __construct(string $message, ?array $variables = null, int $code = 0, bool $protected = false)
        {
            parent::__construct($message, $code);
        }
    }
}

namespace {
    class Model_ClientOrder
    {
    }

    class Registrar_Rdap
    {
    }

    class Model_ActivitySystem
    {
    }

    class Box_LogDb
    {
        public function __construct(string $model)
        {
        }
    }

    class Box_Log
    {
        public function addWriter(object $writer): void
        {
        }

        public function debug(string $message): void
        {
        }

        public function err(string $message): void
        {
        }

        public function warning(string $message): void
        {
        }

        public function info(string $message): void
        {
        }
    }
}
