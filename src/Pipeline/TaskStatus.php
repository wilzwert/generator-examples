<?php

namespace App\Pipeline;

enum TaskStatus
{
    case NOT_STARTED;
    case STARTED;
    case DONE;
}
