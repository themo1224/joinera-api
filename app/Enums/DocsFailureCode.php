<?php

namespace App\Enums;

enum DocsFailureCode: string
{
    case CategoryUnknown = 'category_unknown';
    case NonPdf = 'non_pdf';
    case MissingBaseCv = 'missing_base_cv';
    case LlmEmpty = 'llm_empty';
    case LlmFailed = 'llm_failed';
    case RateLimited = 'rate_limited';
}
