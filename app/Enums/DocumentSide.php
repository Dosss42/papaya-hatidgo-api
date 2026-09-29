<?php

namespace App\Enums;

/** Mirrors driver_document_files.side. */
enum DocumentSide: string
{
    case Front = 'front';
    case Back = 'back';
    case Page = 'page';
}
