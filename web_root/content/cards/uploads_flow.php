<?php
declare(strict_types=1);

final class _uploads_flowCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'uploads_flow';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return '<section class="eel-card-fragment" data-card="uploads-flow">
            <div class="card" style="grid-column: 1 / -1;">
                <div class="card-header">
                    <h2 class="card-title">Workflow</h2>
                </div>
                <div class="card-body" style="overflow-x: auto; padding-top: 4px; padding-bottom: 4px;">
                    <svg width="900" height="180" viewBox="0 0 900 180" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Upload CSV to field mappings to validate and commit workflow">
                        <defs>
                            <marker id="upload-flow-arrowhead" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto">
                                <path d="M0,0 L10,5 L0,10 Z" fill="#405952" />
                            </marker>
                        </defs>
                        <style>
                            .upload-flow-box { fill: #ffffff; stroke: #405952; stroke-width: 2.5; }
                            .upload-flow-label { font-family: Arial, Helvetica, sans-serif; font-size: 22px; fill: #1f2a2e; text-anchor: middle; dominant-baseline: middle; }
                            .upload-flow-arrow { stroke: #405952; stroke-width: 2.5; fill: none; marker-end: url(#upload-flow-arrowhead); }
                        </style>
                        <rect class="upload-flow-box" x="40" y="45" rx="18" ry="18" width="220" height="90" />
                        <text class="upload-flow-label" x="150" y="90">Upload CSV</text>
                        <line class="upload-flow-arrow" x1="260" y1="90" x2="340" y2="90" />
                        <rect class="upload-flow-box" x="340" y="45" rx="18" ry="18" width="220" height="90" />
                        <text class="upload-flow-label" x="450" y="90">Field Mappings</text>
                        <line class="upload-flow-arrow" x1="560" y1="90" x2="640" y2="90" />
                        <rect class="upload-flow-box" x="640" y="45" rx="18" ry="18" width="220" height="90" />
                        <text class="upload-flow-label" x="750" y="90">Validate and Commit</text>
                    </svg>
                </div>
            </div>
        </section>';
    }
}
