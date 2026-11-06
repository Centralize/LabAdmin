<?php
declare(strict_types=1);

namespace LabAdmin;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

/**
 * phpseclib SSH2 + the one thing an interactive terminal needs that phpseclib lacks:
 * resizing a running PTY (SSH "window-change" channel request, RFC 4254 §6.7).
 */
class InteractiveSsh extends SSH2
{
    public function resize(int $cols, int $rows): void
    {
        $cols = max(20, min(1000, $cols));
        $rows = max(5, min(500, $rows));
        if (!isset($this->server_channels[self::CHANNEL_EXEC]) || !$this->isPTYOpen()) {
            return;
        }
        $this->send_binary_packet(Strings::packSSH2(
            'CNsbNNNN',
            NET_SSH2_MSG_CHANNEL_REQUEST,
            $this->server_channels[self::CHANNEL_EXEC],
            'window-change',
            false, // want reply
            $cols,
            $rows,
            0,
            0
        ));
    }
}
