<?php
declare(strict_types=1);
require_once __DIR__ . '/trusted-html.php';

final class SnapOwnerCode
{
    private function __construct(private string $value) {}
    public static function __snapsmackCmsOnly(string $value): self { return new self($value); }
    public function __toString(): string { return $this->value; }
}

function snapsmack_owner_custom_code_enabled(array $settings): bool
{
    return ($settings['owner_custom_code_enabled']??'0')==='1';
}

function snapsmack_owner_custom_head(array $settings): string
{
    if(!snapsmack_owner_custom_code_enabled($settings))return '';
    $path=dirname(__DIR__).'/data/custom-head.html';
    if(is_file($path)){ $value=file_get_contents($path); return is_string($value)?$value:''; }
    return (string)($settings['custom_head_scripts']??'');
}

function snapsmack_owner_custom_code(array $settings): SnapOwnerCode
{
    return SnapOwnerCode::__snapsmackCmsOnly(snapsmack_owner_custom_head($settings));
}

function snapsmack_public_csp(bool $ownerCustomCode=false): string
{
    $script=$ownerCustomCode?"'self' 'unsafe-inline' https:":"'self'";
    return "default-src 'self'; script-src {$script}; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https:; media-src 'self' https:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
}

function snapsmack_emit_public_csp(bool $ownerCustomCode=false): void
{
    if(PHP_SAPI!=='cli'&&!headers_sent())header('Content-Security-Policy: '.snapsmack_public_csp($ownerCustomCode),true);
}
