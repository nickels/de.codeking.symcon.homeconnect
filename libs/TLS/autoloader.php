<?php

$autoloader = new AutoloaderTLS('PTLS');
$autoloader->register();

class AutoloaderTLS
{
    private $namespace;

    public function __construct($namespace = null)
    {
        $this->namespace = $namespace;
    }

    public function register()
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    public function loadClass($className)
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}

class TLSState
{
    const Init = 0;
    const Connecting = 1;
    const Connected = 2;
    const TLSisSend = 3;
    const TLSisReceived = 4;

    /**
     *  Liefert den Klartext zu einem Status.
     *
     * @param int $Code
     * @return string
     */
    public static function ToString(int $Code)
    {
        switch ($Code) {
            case self::unknow:
                return 'unknow';
            case self::HandshakeSend:
                return 'HandshakeSend';
            case self::HandshakeReceived:
                return 'HandshakeReceived';
            case self:: Connected:
                return 'Connected';
            case self:: init:
                return 'init';
            case self:: TLSisSend:
                return 'TLSisSend';
            case self:: TLSisReceived:
                return 'TLSisReceived';
        }
    }
}