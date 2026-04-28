<?php

namespace Spectator\Enums;

enum SpecSource: string
{
    case Local = 'local';
    case Remote = 'remote';
    case Github = 'github';
}
