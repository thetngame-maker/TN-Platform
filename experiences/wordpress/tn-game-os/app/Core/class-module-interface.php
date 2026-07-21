<?php
namespace TNG_OS\Core;
if (!defined('ABSPATH')) exit;

interface Module_Interface {
    public function id(): string;
    public function register(Container $container): void;
    public function boot(Container $container): void;
}
