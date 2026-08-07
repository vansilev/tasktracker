<?php

namespace App\Enums;

/**
 * Where a piece of task/comment content came from, which decides how it is
 * turned into stored HTML.
 *
 * Getting this wrong is a correctness bug in both directions: escaping editor
 * HTML shows users their own tags, and sanitizing plain text silently eats
 * spreadsheet cells that contain "<" or "&".
 */
enum ContentSource
{
    /** Markup produced by the TipTap editor; sanitized against the task_content profile. */
    case Editor;

    /** Literal text (spreadsheet cells, system-generated messages); escaped, never parsed. */
    case PlainText;
}
