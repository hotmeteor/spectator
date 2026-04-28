<?php

namespace Spectator\Support;

// https://joshtronic.com/2013/09/02/how-to-use-colors-in-command-line-output/
enum Format: string
{
    case TextGreen = '0;32';
    case TextRed = '0;31';
    case TextWhite = '1;37';
    case TextLightGrey = '0;37';
    case TextDarkGrey = '1;30';
    case StyleItalic = '3';
}
