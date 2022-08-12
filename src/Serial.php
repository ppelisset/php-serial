<?php

namespace PHPSerial;

use Ioctl\Ioctl;
use Termios\OptionalAction;
use Termios\QueueSelector;
use Termios\Termios;

class Serial
{
    private mixed $stream = null;
    private ?int $fd = null;
    private $rtsState = true;
    private $dtrState = true;

    public function __construct(
        private string   $port,
        private int      $baudRate = 9600,
        private ByteSize $byteSize = ByteSize::EIGHT_BITS,
        private Parity   $parity = Parity::NONE,
        private StopBits $stopBits = StopBits::ONE,
        private bool     $xonxoff = false,
        private bool     $rtscts = false
    )
    {
    }

    public function open(): void
    {
        if ($this->isOpen()) {
            throw new SerialException("Port is already open");
        }
        $this->stream = fopen($this->port, 'r+b');
        $this->fd = fileno($this->stream);
        stream_set_blocking($this->stream, 0);
        $this->reconfigurePort();
        $this->updateDtrAndRtsState();
        Termios::tcflush($this->fd, QueueSelector::IOFLUSH);
    }

    public function isOpen(): bool
    {
        return !is_null($this->stream);
    }

    public function write(string $data): int
    {
        if (fwrite($this->stream, $data) === false) {
            throw new SerialException("Unable to write data to serial port");
        }
        fflush($this->stream);
        return self::countBytes($data);
    }

    public function read(int $count = 0): string
    {
        if (!$this->isOpen()) {
            throw new SerialException("Device must be opened to read it");
        }
        if ($count === 0) {
            return $this->readAllAvailableContent();
        }
        $content = "";
        $i = 0;
        do {
            $readSize = min($count, 128);
            $currentRead = fread($this->stream, $readSize);
            $content .= $currentRead;
        } while (self::countBytes($currentRead) >= $readSize && self::countBytes($content) < $count);
        return $content;

    }

    public function close(): void
    {
        if (!$this->isOpen()) {
            throw new SerialException("Port is not open");
        }
        if (!fclose($this->stream)) {
            throw new SerialException("Unable to close the device");
        }
        $this->stream = null;
        $this->fd = null;
    }

    private function reconfigurePort(): void
    {
        $termios = Termios::tcgetattr($this->fd);
        $this->setUpRawMode($termios);
        $this->setUpBaudRate($termios);
        $this->setUpByteSize($termios);
        $this->setUpStopBits($termios);
        $this->setUpParity($termios);
        $this->setUpXonxoff($termios);
        $this->setUpRtscts($termios);
        $this->setUpVMinVTime($termios);
        Termios::tcsetattr($this->fd, OptionalAction::NOW, $termios);
    }

    private function setUpRawMode(Termios $termios): void
    {
        $termios->cflag |= (Termios::CLOCAL | Termios::CREAD);
        $termios->lflag &= ~(Termios::ICANON | Termios::ECHO | Termios::ECHOE |
            Termios::ECHOK | Termios::ECHONL |
            Termios::ISIG | Termios::IEXTEN);
        $termios->oflag &= ~(Termios::OPOST | Termios::ONLCR | Termios::OCRNL);
        $termios->iflag &= ~(Termios::INLCR | Termios::IGNCR | Termios::ICRNL | Termios::IGNBRK);
        if (Termios::has(Termios::PARMRK)) {
            $termios->iflag &= ~Termios::PARMRK;
        }
    }

    private function setUpBaudRate(Termios $termios): void
    {
        $constantName = sprintf("B%d", $this->baudRate);
        $constantValue = constant(sprintf("%s::%s", Termios::class, $constantName));
        if (!Termios::has($constantValue)) {
            throw new SerialException("Not supported BaudRate $this->baudRate");
        }
        $termios->ospeed = $constantValue;
        $termios->ispeed = $constantValue;
    }

    private function setUpByteSize(Termios $termios): void
    {
        $termios->cflag &= ~Termios::CSIZE;
        $termios->cflag |= $this->byteSize->getCFlag();
    }

    private function setUpStopBits(Termios $termios): void
    {
        if ($this->stopBits === StopBits::ONE) {
            $termios->cflag &= ~(Termios::CSTOPB);
            return;
        }
        $termios->cflag |= Termios::CSTOPB;
    }

    private function setUpParity(Termios $termios): void
    {
        $termios->iflag &= ~(Termios::INPCK | Termios::ISTRIP);
        switch ($this->parity) {
            case Parity::NONE:
                $termios->cflag &= ~(Termios::PARENB | Termios::PARODD | Termios::CMSPAR);
                break;
            case Parity::EVEN:
                $termios->cflag &= ~(Termios::PARODD | Termios::CMSPAR);
                $termios->cflag |= (Termios::PARENB);
                break;
            case Parity::ODD:
                $termios->cflag &= ~Termios::CMSPAR;
                $termios->cflag |= (Termios::PARENB | Termios::PARODD);
                break;
            case Parity::MARK:
                if (!Termios::has(Termios::CMSPAR)) {
                    throw new SerialException("Not supported on this platform");
                }
                $termios->cflag |= (Termios::PARENB | Termios::CMSPAR | Termios::PARODD);
                break;
            case Parity::SPACE:
                if (!Termios::has(Termios::CMSPAR)) {
                    throw new SerialException("Not supported on this platform");
                }
                $termios->cflag |= (Termios::PARENB | Termios::CMSPAR);
                $termios->cflag &= ~(Termios::PARODD);
                break;
        }
    }

    private function setUpXonxoff(Termios $termios)
    {
        if ($this->xonxoff) {
            $termios->iflag |= Termios::IXON | Termios::IXOFF;
            return;
        }
        $termios->iflag &= ~(Termios::IXON | Termios::IXOFF | Termios::IXANY);
    }

    private function setUpRtscts(Termios $termios)
    {
        // Support CNEW_RTSCTS ?
        $flagToSet = Termios::has(Termios::CRTSCTS) ? Termios::CRTSCTS : 0;
        if ($this->rtscts) {
            $termios->cflag |= $flagToSet;
            return;
        }
        $termios->cflag &= ~$flagToSet;
    }

    private function setUpVMinVTime(Termios $termios)
    {
        $termios->cc[Termios::VMIN] = 0;
        $termios->cc[Termios::VTIME] = 0;
    }

    private function updateDtrAndRtsState()
    {
        $tiocmbis = Ioctl::attr(Ioctl::TIOCMBIS, 0x5416);
        $tiocmbic = Ioctl::attr(Ioctl::TIOCMBIC, 0);
        $tiomDtr = Ioctl::attr(Ioctl::TIOCM_DTR, 0x002);
        $tiomRts = Ioctl::attr(Ioctl::TIOCM_RTS, 0x004);

        Ioctl::ioctl($this->fd, $this->dtrState ? $tiocmbis : $tiocmbic, pack('I', $tiomDtr));
        Ioctl::ioctl($this->fd, $this->rtsState ? $tiocmbis : $tiocmbic, pack('I', $tiomRts));
    }

    private function readAllAvailableContent(): string
    {
        $content = "";
        $i = 0;
        do {
            $content .= fread($this->stream, 128);
        } while (($i += 128) === strlen($content));
        return $content;
    }

    private static function countBytes(string $data): int
    {
        return mb_strlen($data, encoding: '8bit');
    }
}