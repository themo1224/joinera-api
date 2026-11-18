<?php

namespace App\Enums;

enum DocumentPageRole: string
{
    case Cover = 'cover';
    case Anschreiben = 'anschreiben';
    case Cv = 'cv';
    case Passport = 'passport';
    case LanguageCert = 'language_cert';
    case EducationCert = 'education_cert';
    case ExperienceProof = 'experience_proof';
    case PhotoCollage = 'photo_collage';
    case CertLegend = 'cert_legend';
    case WatermarkOnly = 'watermark_only';
    case Unknown = 'unknown';
    case Skip = 'skip';
}
