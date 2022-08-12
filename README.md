# php-serial

This package is a test to port base of pyserial in pure PHP with FFI. It append a Serial object to access to a serial port on Linux/Darwin (MacOS).

## Installation
php-serial require PHP8.1 and php-ffi enabled. To install this package, use composer to require package `ppelisset/php-serial`.

## Documentation
`PHPSerial\Serial::__construct()` - Create a serial object with port configuration

`PHPSerial\Serial::open` - Open port and configure system to access to him

`PHPSerial\Serial::isOpen` - Check port is currently open

`PHPSerial\Serial::read` - Read a max of `$count` byte on serial port (less if not enough bytes is available). Default value is `0`, to read all available bytes.

`PHPSerial\Serial::write` - Write data on serial port

`PHPSerial\Serial::close` - Close port access