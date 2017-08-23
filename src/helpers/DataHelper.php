<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-23
 * Time: 13:50
 */

namespace inhere\webSocket\utils;

/**
 * Class DataHelper
 * @package inhere\webSocket\utils
 */
class DataHelper
{

    protected function frame($message, $user, $messageType = 'text', $messageContinues = false)
    {
        switch ($messageType) {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = ($user->sendingContinuous) ? 0 : 1;
                break;
            case 'binary':
                $b1 = ($user->sendingContinuous) ? 0 : 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
            default:
                throw new \InvalidArgumentException('Error message type value.');
                break;
        }

        if ($messageContinues) {
            $user->sendingContinuous = true;
        } else {
            $b1 += 128;
            $user->sendingContinuous = false;
        }
        $length = strlen($message);
        $lengthField = "";
        if ($length < 126) {
            $b2 = $length;
        } elseif ($length < 65536) {
            $b2 = 126;
            $hexLength = dechex($length);
            //$this->stdout("Hex Length: $hexLength");
            if (strlen($hexLength) % 2 === 1) {
                $hexLength = '0' . $hexLength;
            }

            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i -= 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }

            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } else {
            $b2 = 127;
            $hexLength = dechex($length);

            if (strlen($hexLength) % 2 === 1) {
                $hexLength = '0' . $hexLength;
            }

            $n = strlen($hexLength) - 2;
            for ($i = $n; $i >= 0; $i -= 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }

            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }

        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    //check packet if he have more than one frame and process each frame individually
    protected function split_packet($length, $packet, $user)
    {
        //add PartialPacket and calculate the new $length
        if ($user->handlingPartialPacket) {
            $packet = $user->partialBuffer . $packet;
            $user->handlingPartialPacket = false;
            $length = strlen($packet);
        }

        $fullpacket = $packet;
        $frame_pos = 0;
        $frame_id = 1;

        while ($frame_pos < $length) {
            $headers = $this->extractHeaders($packet);
            $headers_size = $this->calcoffset($headers);
            $frameSize = $headers['length'] + $headers_size;

            //split frame from packet and process it
            $frame = substr($fullpacket, $frame_pos, $frameSize);
            
            if (($message = $this->deFrame($frame, $user, $headers)) !== FALSE) {
                if ($user->hasSentClose) {
                    $this->disconnect($user->socket);
                } else {
                    if ((preg_match('//u', $message)) || ($headers['opcode'] == 2)) {
                        //$this->stdout("Text msg encoded UTF-8 or Binary msg\n".$message);
                        $this->process($user, $message);
                    } else {
                        $this->stderr("not UTF-8\n");
                    }
                }
            }
            
            //get the new position also modify packet data
            $frame_pos += $frameSize;
            $packet = substr($fullpacket, $frame_pos);
            $frame_id++;
        }
    }

    protected function calcoffset($headers)
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }

        return $offset;
    }

    protected function deFrame($message, &$user)
    {
        //echo $this->strToHex($message);
        $headers = $this->extractHeaders($message);
        $pongReply = $willClose = false;
        
        switch ($headers['opcode']) {
            case 0:
            case 1:
            case 2:
                break;
            case 8:
                // todo: close the connection
                $user->hasSentClose = true;

                return '';
            case 9:
                $pongReply = true;
                break;
            case 10:
                break;
            default:
                //$this->disconnect($user); // todo: fail connection
                $willClose = true;
                break;
        }
        /* Deal by split_packet() as now deFrame() do only one frame at a time.
        if ($user->handlingPartialPacket) {
          $message = $user->partialBuffer . $message;
          $user->handlingPartialPacket = false;
          return $this->deFrame($message, $user);
        }
        */

        if ($this->checkRSVBits($headers, $user)) {
            return false;
        }

        if ($willClose) {
            // todo: fail the connection
            return false;
        }

        $payload = $user->partialMessage . $this->extractPayload($message, $headers);

        if ($pongReply) {
            $reply = $this->frame($payload, $user, 'pong');
            socket_write($user->socket, $reply, strlen($reply));

            return false;
        }

        if ($headers['length'] > strlen($this->applyMask($headers, $payload))) {
            $user->handlingPartialPacket = true;
            $user->partialBuffer = $message;

            return false;
        }

        $payload = $this->applyMask($headers, $payload);
        if ($headers['fin']) {
            $user->partialMessage = '';

            return $payload;
        }

        $user->partialMessage = $payload;

        return false;
    }

    protected function extractHeaders($message)
    {
        $header = [
            'fin' => $message[0] & chr(128),
            'rsv1' => $message[0] & chr(64),
            'rsv2' => $message[0] & chr(32),
            'rsv3' => $message[0] & chr(16),
            'opcode' => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length' => 0,
            'mask' => ''
        ];

        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);

        if ($header['length'] === 126) {
            if ($header['hasmask']) {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }

            $header['length'] = ord($message[2]) * 256 + ord($message[3]);
        } elseif ($header['length'] === 127) {

            if ($header['hasmask']) {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }

            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
                + ord($message[3]) * 65536 * 65536 * 65536
                + ord($message[4]) * 65536 * 65536 * 256
                + ord($message[5]) * 65536 * 65536
                + ord($message[6]) * 65536 * 256
                + ord($message[7]) * 65536
                + ord($message[8]) * 256
                + ord($message[9]);

        } elseif ($header['hasmask']) {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }

        //echo $this->strToHex($message);
        //$this->printHeaders($header);

        return $header;
    }

    protected function extractPayload($message, $headers)
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }

        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }

        return substr($message, $offset);
    }

    protected function applyMask($headers, $payload)
    {
        $effectiveMask = '';
        if ($headers['hasmask']) {
            $mask = $headers['mask'];
        } else {
            return $payload;
        }

        while (strlen($effectiveMask) < strlen($payload)) {
            $effectiveMask .= $mask;
        }

        while (strlen($effectiveMask) > strlen($payload)) {
            $effectiveMask = substr($effectiveMask, 0, -1);
        }

        return $effectiveMask ^ $payload;
    }

    protected function checkRSVBits($headers, $user)
    {
        // override this method if you are using an extension where the RSV bits are used.
        if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
            //$this->disconnect($user); // todo: fail connection
            return true;
        }

        return false;
    }

    protected function strToHex($str)
    {
        $len = strlen($str);
        $strOut = '';

        for ($i = 0; $i < $len; $i++) {
            $strOut .= (ord($str[$i]) < 16) ? '0' . dechex(ord($str[$i])) : dechex(ord($str[$i]));
            $strOut .= ' ';
            $remainder = $i % 32;

            if ($remainder === 7) {
                $strOut .= ': ';
            }

            if ($remainder === 15) {
                $strOut .= ': ';
            }
            if ($remainder === 23) {
                $strOut .= ': ';
            }
            if ($remainder === 31) {
                $strOut .= "\n";
            }
        }

        return $strOut . "\n";
    }
}