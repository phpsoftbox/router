<?php

namespace PhpSoftBox\Router;

enum ParamTypesEnum: string
{
    case INT    = 'int';
    case STRING = 'string';
    case CUSTOM = 'custom';
}
