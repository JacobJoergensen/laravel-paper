<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\StorageAdapters;

enum ConditionalWriteStatus
{
    case Written;
    case Removed;
    case Mismatch;
    case Missing;
    case Taken;
    case Failed;
}
