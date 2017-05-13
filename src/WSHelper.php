<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/3 0003
 * Time: 01:05
 */

namespace inhere\webSocket;

/**
 * Class WSHelper
 * @package inhere\webSocket
 */
class WSHelper
{
    public static function encode()
    {

    }


    /**
     * @param $s
     * @return string
     */
    public static function frame($s)
    {
        $a = str_split($s, 125);
        $prefix = WSInterface::BINARY_TYPE_BLOB;

        if (count($a) === 1) {
            return $prefix . chr(strlen($a[0])) . $a[0];
        }

        $ns = '';

        foreach ($a as $o) {
            $ns .= $prefix . chr(strlen($o)) . $o;
        }

        return $ns;
    }

    /**
     * @param $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        /*$len = $masks = $data =*/
        $decoded = '';
        $len = ord($buffer[1]) & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        $dataLen = strlen($data);
        for ($index = 0; $index < $dataLen; $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return $decoded;
    }

    /**
     * @param $payload
     * @param string $type
     * @param bool $masked
     * @return bool|string
     */
    public static function hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $payloadLength = strlen($payload);

        switch ($type) {
            //文本内容
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            //二进制内容
            case 'binary':
            case 'bin':
                // first byte indicates FIN, Text-Frame (10000010):
                $frameHead[0] = 130;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;

            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }

            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                // todo `$this->close()`;
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach ($frameHead as $i => $v) {
            $frameHead[$i] = chr($frameHead[$i]);
        }

        // generate a random mask:
        $mask = array();
        if ($masked === true) {
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(random_int(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }

        $frame = implode('', $frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= $masked ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    /**
     * @param $data
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function hybi10Decode($data)
    {
        if (!$data) {
            throw new \InvalidArgumentException('data is empty');
        }

        $bytes = $data;
        $secondByte = sprintf('%08b', ord($bytes[1]));
        $masked = '1' === $secondByte[0];
        $dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);

        //服务器不会设置mask
        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } elseif ($dataLength === 127) {
            $decodedData = substr($bytes, 10);
        } else {
            $decodedData = substr($bytes, 2);
        }

        return $decodedData;
    }

}
