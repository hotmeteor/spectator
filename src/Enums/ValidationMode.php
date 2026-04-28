<?php

namespace Spectator\Enums;

enum ValidationMode: string
{
    case Read = 'read';
    case Write = 'write';
}
