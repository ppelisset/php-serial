<?php

namespace PHPSerial;

use Termios\Termios;

enum ByteSize: int
{
    case FIVE_BITS = 5;
    case SIX_BITS = 6;
    case SEVEN_BITS = 7;
    case EIGHT_BITS = 8;

    public function getCFlag(): int
    {
        return match ($this) {
            self::FIVE_BITS => Termios::CS5,
            self::SIX_BITS => Termios::CS6,
            self::SEVEN_BITS => Termios::CS7,
            self::EIGHT_BITS => Termios::CS8
        };
    }
}