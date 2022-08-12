<?php

namespace PHPSerial;

enum Parity
{
    case NONE;
    case EVEN;
    case ODD;
    case MARK;
    case SPACE;
}