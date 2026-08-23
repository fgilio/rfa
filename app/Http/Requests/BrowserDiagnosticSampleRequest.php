<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The accepted shape of a browser diagnostic sample.
 *
 * The renderer builds these samples in public/js/runtime-diagnostics.js and
 * posts one JSON body per sample. Every key the endpoint stores is named
 * here, so an unknown one is a 422 rather than a line in the diagnostics
 * log, and BrowserDiagnosticSampleFormatter can trust what it receives.
 */
final class BrowserDiagnosticSampleRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'hidden' => ['nullable', 'boolean'],
            'focused' => ['nullable', 'boolean'],
            'includeProcessSnapshot' => ['nullable', 'boolean'],
            'viewport' => ['nullable', 'array:width,height,devicePixelRatio'],
            'viewport.width' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'viewport.height' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'viewport.devicePixelRatio' => ['nullable', 'numeric', 'min:0', 'max:16'],
            'screen' => ['nullable', 'array:width,height,availWidth,availHeight'],
            'screen.width' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'screen.height' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'screen.availWidth' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'screen.availHeight' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'visibility' => ['nullable', 'array:state,hidden,focused,focusAgeMs,visibilityAgeMs'],
            'visibility.state' => ['nullable', 'string', 'max:32'],
            'visibility.hidden' => ['nullable', 'boolean'],
            'visibility.focused' => ['nullable', 'boolean'],
            'visibility.focusAgeMs' => ['nullable', 'integer', 'min:0'],
            'visibility.visibilityAgeMs' => ['nullable', 'integer', 'min:0'],
            'activity' => ['nullable', 'array:idleMs,lastEvent'],
            'activity.idleMs' => ['nullable', 'integer', 'min:0'],
            'activity.lastEvent' => ['nullable', 'string', 'max:64'],
            'scroll' => ['nullable', 'array:x,y,maxY'],
            'scroll.x' => ['nullable', 'integer', 'min:0'],
            'scroll.y' => ['nullable', 'integer', 'min:0'],
            'scroll.maxY' => ['nullable', 'integer', 'min:0'],
            'heap' => ['nullable', 'array:usedJSHeapSize,totalJSHeapSize,jsHeapSizeLimit,usedJSHeapSizeMb,totalJSHeapSizeMb'],
            'heap.usedJSHeapSize' => ['nullable', 'numeric', 'min:0'],
            'heap.totalJSHeapSize' => ['nullable', 'numeric', 'min:0'],
            'heap.jsHeapSizeLimit' => ['nullable', 'numeric', 'min:0'],
            'heap.usedJSHeapSizeMb' => ['nullable', 'numeric', 'min:0'],
            'heap.totalJSHeapSizeMb' => ['nullable', 'numeric', 'min:0'],
            'dom' => ['nullable', 'array:nodes,livewireComponents,diffFiles,expandedDiffFiles,diffLines,comments,animatedElements,animateSpin,animatePing,animatePulse,backdropBlur,sticky'],
            'dom.nodes' => ['nullable', 'integer', 'min:0'],
            'dom.livewireComponents' => ['nullable', 'integer', 'min:0'],
            'dom.diffFiles' => ['nullable', 'integer', 'min:0'],
            'dom.expandedDiffFiles' => ['nullable', 'integer', 'min:0'],
            'dom.diffLines' => ['nullable', 'integer', 'min:0'],
            'dom.comments' => ['nullable', 'integer', 'min:0'],
            'dom.animatedElements' => ['nullable', 'integer', 'min:0'],
            'dom.animateSpin' => ['nullable', 'integer', 'min:0'],
            'dom.animatePing' => ['nullable', 'integer', 'min:0'],
            'dom.animatePulse' => ['nullable', 'integer', 'min:0'],
            'dom.backdropBlur' => ['nullable', 'integer', 'min:0'],
            'dom.sticky' => ['nullable', 'integer', 'min:0'],
            'animations' => ['nullable', 'array:activeCount,runningCount,cssAnimationCount,cssTransitionCount,classSummary,elementGroups,elements'],
            'animations.activeCount' => ['nullable', 'integer', 'min:0'],
            'animations.runningCount' => ['nullable', 'integer', 'min:0'],
            'animations.cssAnimationCount' => ['nullable', 'integer', 'min:0'],
            'animations.cssTransitionCount' => ['nullable', 'integer', 'min:0'],
            'animations.classSummary' => ['nullable', 'array', 'max:50'],
            'animations.classSummary.*' => ['array:name,count'],
            'animations.classSummary.*.name' => ['nullable', 'string', 'max:96'],
            'animations.classSummary.*.count' => ['nullable', 'integer', 'min:0'],
            'animations.elementGroups' => ['nullable', 'array', 'max:50'],
            'animations.elementGroups.*' => ['array:signature,count,runningCount,animationNames,classes,nearestLivewireName,nearestTestId,nearestInteractiveSignature,nearestButtonLabel,nearestButtonText,nearestButtonTitle,nearestButtonRole,nearestButtonDisabled,nearestLoading,nearestWireClick,nearestWireTarget'],
            'animations.elementGroups.*.signature' => ['nullable', 'string', 'max:180'],
            'animations.elementGroups.*.count' => ['nullable', 'integer', 'min:0'],
            'animations.elementGroups.*.runningCount' => ['nullable', 'integer', 'min:0'],
            'animations.elementGroups.*.animationNames' => ['nullable', 'array', 'max:12'],
            'animations.elementGroups.*.animationNames.*' => ['string', 'max:96'],
            'animations.elementGroups.*.classes' => ['nullable', 'array', 'max:20'],
            'animations.elementGroups.*.classes.*' => ['string', 'max:96'],
            'animations.elementGroups.*.nearestLivewireName' => ['nullable', 'string', 'max:128'],
            'animations.elementGroups.*.nearestTestId' => ['nullable', 'string', 'max:96'],
            'animations.elementGroups.*.nearestInteractiveSignature' => ['nullable', 'string', 'max:180'],
            'animations.elementGroups.*.nearestButtonLabel' => ['nullable', 'string', 'max:120'],
            'animations.elementGroups.*.nearestButtonText' => ['nullable', 'string', 'max:120'],
            'animations.elementGroups.*.nearestButtonTitle' => ['nullable', 'string', 'max:120'],
            'animations.elementGroups.*.nearestButtonRole' => ['nullable', 'string', 'max:64'],
            'animations.elementGroups.*.nearestButtonDisabled' => ['nullable', 'boolean'],
            'animations.elementGroups.*.nearestLoading' => ['nullable', 'boolean'],
            'animations.elementGroups.*.nearestWireClick' => ['nullable', 'string', 'max:160'],
            'animations.elementGroups.*.nearestWireTarget' => ['nullable', 'string', 'max:160'],
            'animations.elements' => ['nullable', 'array', 'max:50'],
            'animations.elements.*' => ['array:signature,tag,id,testId,role,classes,animationNames,playStates,animationCount,runningCount,maxDurationMs,connected,visible,nearestLivewireId,nearestLivewireName,nearestTestId,nearestDiffFileState,nearestInteractiveSignature,nearestButtonLabel,nearestButtonText,nearestButtonTitle,nearestButtonRole,nearestButtonDisabled,nearestLoading,nearestWireClick,nearestWireTarget,rectX,rectY,rectWidth,rectHeight,computedDisplay,computedVisibility,computedOpacity,computedPointerEvents,cssAnimationName,cssAnimationDuration,cssAnimationPlayState'],
            'animations.elements.*.signature' => ['nullable', 'string', 'max:180'],
            'animations.elements.*.tag' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.id' => ['nullable', 'string', 'max:64'],
            'animations.elements.*.testId' => ['nullable', 'string', 'max:96'],
            'animations.elements.*.role' => ['nullable', 'string', 'max:64'],
            'animations.elements.*.classes' => ['nullable', 'array', 'max:20'],
            'animations.elements.*.classes.*' => ['string', 'max:96'],
            'animations.elements.*.animationNames' => ['nullable', 'array', 'max:12'],
            'animations.elements.*.animationNames.*' => ['string', 'max:96'],
            'animations.elements.*.playStates' => ['nullable', 'array', 'max:8'],
            'animations.elements.*.playStates.*' => ['string', 'max:32'],
            'animations.elements.*.animationCount' => ['nullable', 'integer', 'min:0'],
            'animations.elements.*.runningCount' => ['nullable', 'integer', 'min:0'],
            'animations.elements.*.maxDurationMs' => ['nullable', 'integer', 'min:0'],
            'animations.elements.*.connected' => ['nullable', 'boolean'],
            'animations.elements.*.visible' => ['nullable', 'boolean'],
            'animations.elements.*.nearestLivewireId' => ['nullable', 'string', 'max:64'],
            'animations.elements.*.nearestLivewireName' => ['nullable', 'string', 'max:128'],
            'animations.elements.*.nearestTestId' => ['nullable', 'string', 'max:96'],
            'animations.elements.*.nearestDiffFileState' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.nearestInteractiveSignature' => ['nullable', 'string', 'max:180'],
            'animations.elements.*.nearestButtonLabel' => ['nullable', 'string', 'max:120'],
            'animations.elements.*.nearestButtonText' => ['nullable', 'string', 'max:120'],
            'animations.elements.*.nearestButtonTitle' => ['nullable', 'string', 'max:120'],
            'animations.elements.*.nearestButtonRole' => ['nullable', 'string', 'max:64'],
            'animations.elements.*.nearestButtonDisabled' => ['nullable', 'boolean'],
            'animations.elements.*.nearestLoading' => ['nullable', 'boolean'],
            'animations.elements.*.nearestWireClick' => ['nullable', 'string', 'max:160'],
            'animations.elements.*.nearestWireTarget' => ['nullable', 'string', 'max:160'],
            'animations.elements.*.rectX' => ['nullable', 'integer'],
            'animations.elements.*.rectY' => ['nullable', 'integer'],
            'animations.elements.*.rectWidth' => ['nullable', 'integer', 'min:0'],
            'animations.elements.*.rectHeight' => ['nullable', 'integer', 'min:0'],
            'animations.elements.*.computedDisplay' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.computedVisibility' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.computedOpacity' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.computedPointerEvents' => ['nullable', 'string', 'max:32'],
            'animations.elements.*.cssAnimationName' => ['nullable', 'string', 'max:96'],
            'animations.elements.*.cssAnimationDuration' => ['nullable', 'string', 'max:64'],
            'animations.elements.*.cssAnimationPlayState' => ['nullable', 'string', 'max:64'],
            'navigation' => ['nullable', 'array:type,domCompleteMs,resources'],
            'navigation.type' => ['nullable', 'string', 'max:64'],
            'navigation.domCompleteMs' => ['nullable', 'integer', 'min:0'],
            'navigation.resources' => ['nullable', 'integer', 'min:0'],
            'poll' => ['nullable', 'array:source,method,intervalMs,ageMs,hidden,focused'],
            'poll.source' => ['nullable', 'string', 'max:96'],
            'poll.method' => ['nullable', 'string', 'max:96'],
            'poll.intervalMs' => ['nullable', 'integer', 'min:0'],
            'poll.ageMs' => ['nullable', 'integer', 'min:0'],
            'poll.hidden' => ['nullable', 'boolean'],
            'poll.focused' => ['nullable', 'boolean'],
            'timings' => ['nullable', 'array:longTasks,longTasksDuringAction,longTasksDuringCommit,diffAction,livewireCommit'],
            'timings.longTasks' => ['nullable', 'array:count,totalMs,maxMs'],
            'timings.longTasks.count' => ['nullable', 'integer', 'min:0'],
            'timings.longTasks.totalMs' => ['nullable', 'integer', 'min:0'],
            'timings.longTasks.maxMs' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringAction' => ['nullable', 'array:count,totalMs,maxMs'],
            'timings.longTasksDuringAction.count' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringAction.totalMs' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringAction.maxMs' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringCommit' => ['nullable', 'array:count,totalMs,maxMs'],
            'timings.longTasksDuringCommit.count' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringCommit.totalMs' => ['nullable', 'integer', 'min:0'],
            'timings.longTasksDuringCommit.maxMs' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction' => ['nullable', 'array:fileId,action,elapsedMs,phpMs,hunkCount,diffLines,lineContentBytes,tooLarge,binary,cached'],
            'timings.diffAction.fileId' => ['nullable', 'string', 'max:128'],
            'timings.diffAction.action' => ['nullable', 'string', 'max:64'],
            'timings.diffAction.elapsedMs' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction.phpMs' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction.hunkCount' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction.diffLines' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction.lineContentBytes' => ['nullable', 'integer', 'min:0'],
            'timings.diffAction.tooLarge' => ['nullable', 'boolean'],
            'timings.diffAction.binary' => ['nullable', 'boolean'],
            'timings.diffAction.cached' => ['nullable', 'boolean'],
            'timings.livewireCommit' => ['nullable', 'array:status,elapsedMs,componentId,componentName,callCount,calls,updateCount,updateKeys,pollSource,pollMethod,pollAgeMs'],
            'timings.livewireCommit.status' => ['nullable', 'string', 'max:32'],
            'timings.livewireCommit.elapsedMs' => ['nullable', 'integer', 'min:0'],
            'timings.livewireCommit.componentId' => ['nullable', 'string', 'max:64'],
            'timings.livewireCommit.componentName' => ['nullable', 'string', 'max:128'],
            'timings.livewireCommit.callCount' => ['nullable', 'integer', 'min:0'],
            'timings.livewireCommit.calls' => ['nullable', 'array', 'max:20'],
            'timings.livewireCommit.calls.*' => ['string', 'max:96'],
            'timings.livewireCommit.updateCount' => ['nullable', 'integer', 'min:0'],
            'timings.livewireCommit.updateKeys' => ['nullable', 'array', 'max:20'],
            'timings.livewireCommit.updateKeys.*' => ['string', 'max:96'],
            'timings.livewireCommit.pollSource' => ['nullable', 'string', 'max:96'],
            'timings.livewireCommit.pollMethod' => ['nullable', 'string', 'max:96'],
            'timings.livewireCommit.pollAgeMs' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Reject a key the endpoint does not store.
     *
     * The `array:` rules already reject an unknown nested key. A top-level one
     * would instead drop out of validated() without a word, so every depth
     * fails the same way.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            collect(array_keys($this->all()))
                ->map(fn (int|string $field): string => (string) $field)
                ->diff($this->acceptedFields())
                ->each(fn (string $field) => $validator->errors()->add(
                    $field,
                    "The {$field} field is not a browser diagnostic sample field.",
                ));
        }];
    }

    /**
     * The sample fields the endpoint stores, which are the rule keys that name
     * no nested path.
     *
     * @return Collection<int, string>
     */
    public function acceptedFields(): Collection
    {
        return collect(array_keys($this->rules()))
            ->reject(fn (string $field): bool => str_contains($field, '.'))
            ->values();
    }

    /**
     * Reject a body larger than the configured budget before Laravel decodes it.
     *
     * The rules bound each field but not the total, and a renderer bug (a diff
     * with thousands of animated rows, say) can still produce a body worth
     * refusing outright.
     */
    protected function prepareForValidation(): void
    {
        abort_if(
            strlen($this->getContent()) > (int) config('rfa.diagnostics.max_browser_payload_bytes', 64 * 1024),
            413,
        );
    }
}
