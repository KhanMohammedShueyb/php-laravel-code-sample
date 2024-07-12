<?php

namespace App\Http\Controllers;

use App\Clients\NMI\NMIClient;
use App\Clients\Vendasta\VendastaClient;
use App\Events\Contact\ContactOrgUpdated;
use App\Helpers\CommonDBHelper;
use App\Helpers\CustomFieldValuesHelper;
use App\Http\Resources\CompactOrganizationResource;
use App\Http\Resources\OrganizationAddressResource;
use App\Http\Resources\OrganizationResource;
use App\Jobs\ExportOrganizationJob;
use App\Jobs\IntegrationSyncOrganizationJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\DocumentFolder;
use App\Models\DocumentFolderFile;
use App\Models\JobMonitor;
use App\Models\Organization;
use App\Models\OrganizationAddress;
use App\Models\OrganizationSubscription;
use App\Models\PaymentMethod;
use App\Models\SubscriptionSupportHour;
use App\Models\Transaction;
use App\Models\TransactionInvoice;
use Cache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    // GET organizations/
    public function getIndex(Request $request)
    {
        $data = $request->validate([
            'page' => 'sometimes',
            'perPage' => 'sometimes',
            'search' => 'sometimes',
            'ownerId' => 'sometimes',
            'status' => 'sometimes',
            'sortBy' => 'sometimes',
            'sortDirection' => 'sometimes',
            'hasDeal' => 'sometimes',
            'isEscalate' => 'sometimes',
            'requestId' => 'sometimes',
            'brand' => 'sometimes',
            'tags' => 'sometimes',
            'tagIds' => 'sometimes',
        ]);

        $customFields = collect($request->all())->filter(fn ($item, $name) => strpos($name, 'field-') !== false)->toArray();
        $tagIds = optional($data)['tagIds'] ?? [];

        $orgs = Organization::with(['brand', 'company', 'owner', 'user', 'address', 'contact', 'customFieldValue', 'orgStatus'])
            ->where('company_id', Company::id())
            ->orderBy(optional($data)['sortBy'] ?? 'name', optional($data)['sortDirection'] ?? 'asc');

        if ($request->has('search')  && !empty($request->input('search'))) {
            $orgs = $orgs->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->has('ownerId') && !empty($request->input('ownerId'))) {
            $orgs = $orgs->where('owner_id', $data['ownerId']);
        }
        if ($request->has('status')) {
            $orgs = $orgs->where('status', $data['status']);
        }
        if ($request->has('brand')) {
            $orgs = $orgs->where('brand_id', $data['brand']);
        }
        if (optional($data)['hasDeal'] == 'yes') {
            $orgs = $orgs->has('activeDeal');
        } else if (optional($data)['hasDeal'] == 'no') {
            $orgs = $orgs->doesntHave('activeDeal');
        }

        if (optional($data)['isEscalate'] == 1) {
            $orgs->whereHas('tickets', function ($inner) {
                $inner->whereNotNull('tenant_id');
            });
            return CompactOrganizationResource::collection($orgs->get());
        }

        if (count($customFields)) {
            $fields = collect($customFields)->map(fn ($values, $item) => str_replace('field-', '', $item))->toArray();
            $dbFields = CustomField::whereIn('id', $fields);
            $orgIds = collect();
            $dbFields->each(function ($item) use ($customFields, &$orgIds) {
                $values = collect($customFields)->filter(fn ($values, $i) => str_replace('field-', '', $i) == $item->id)->values()[0];

                if ($item->type == 'dropdown') {
                    $ids = CustomFieldValue::where('field_id', $item->id)->whereIn('value', $values)->pluck('object_id')->toArray();
                    $orgIds = $orgIds->concat($ids);
                } else if ($item->type == 'singleOption') {
                    $values = $item->option()->whereIn('id', $values)->pluck('value')->toArray();
                    $ids = CustomFieldValue::where('field_id', $item->id)->whereIn('value', $values)->pluck('object_id')->toArray();
                    $orgIds = $orgIds->concat($ids);
                } else if ($item->type == 'multiOptions') {
                    $values = array_map('intval', $values);
                    $ids = CustomFieldValue::where('field_id', $item->id)->whereJsonContains('value', $values)->pluck('object_id')->toArray();
                    $orgIds = $orgIds->concat($ids);
                }
            });
            $orgs = $orgs->whereIn('id', $orgIds);
        }


        if (count($tagIds)) {
            foreach ($tagIds as $tag) {
                $orgs->whereHas('tags', function ($inner) use ($tag) {
                    $inner->where('id', $tag);
                });
            }
        }

        $orgs = $orgs->applyPermissions()->paginate($data['perPage'] ?? 25);
        return CompactOrganizationResource::collection($orgs)->additional([
            'additional' => ['requestId' => optional($data)['requestId']]
        ]);
    }

    public function getAll(Request $request)
    {
        $orgs = Organization::with(['brand', 'company', 'owner', 'user', 'address'])
            ->where('company_id', Company::id());

        return CompactOrganizationResource::collection($orgs->get());
    }

    public function postCanCreate(Request $request, $orgId = 0)
    {
        $data = $this->validateRequest($request);
        $org = Organization::where('name', $data['name'])->where('id', '!=', $orgId)->first();

        return [
            'id' => optional($org)->id ?? 0,
            'canSee' => optional(Organization::where('name', $data['name'])->applyPermissions()->first())->id > 0,
        ];
    }

    private function validateRequest(Request $request)
    {
        $rules = [
            'name'   => 'required',
            'isVip'  => 'sometimes',
            'status' => 'required',
            'brand'  => 'required',
            'owner'  => 'sometimes',
            'termId' => 'sometimes',
            'tierId' => 'sometimes',
            'isTaxExempt' => 'sometimes',
            'taxExemptReasonId' => 'sometimes',
            'currency' => 'sometimes',
        ];
        $isCreateContact = filter_var($request->input('isContact'), FILTER_VALIDATE_BOOLEAN);

        if ($isCreateContact) {
            $rules['firstName'] = 'required';
            $rules['lastName']  = 'required';
            $rules['title']    = 'sometimes';
            $rules['phones']    = 'sometimes';
            $rules['emails']    = 'sometimes';
        }

        $data = $request->validate($rules);
        return $data;
    }

    public function postCreate(Request $request)
    {
        $data = $this->validateRequest($request);
        $isCreateContact = filter_var($request->input('isContact'), FILTER_VALIDATE_BOOLEAN);

        $org = Organization::create([
            'name'           => $data['name'],
            'is_vip'         => $data['isVip'],
            'status'         => $data['status'],
            'brand_id'       => $data['brand'],
            'owner_id'       => $data['owner'],
            'company_id'     => Company::id(),
            'last_activity'  => Carbon::now(),
            'user_id'        => \Auth::user()->id,
            'term_id'        => optional($data)['termId'] ?? 0,
            'tier_id'        => optional($data)['tierId'] ?? 0,
            'is_tax_exempt'  => optional($data)['isTaxExempt'] ?? 0,
            'currency'       => optional($data)['currency'] ?? Company::current()->currency,
        ]);
        $contact    = false;

        if ($isCreateContact) {
            $contact = $org->contact()->create([
                'company_id' => Company::id(),
                'first_name' => $data['firstName'],
                'last_name' => $data['lastName'],
                'status' => '1',
                'owner_id' => $data['owner'],
                'created_by' => Auth::id(),
                'title' => optional($data)['title'],
            ]);

            $phones = optional($data)['phones'] ?? [];
            $emails = optional($data)['emails'] ?? [];
            $contactCont = new ContactController();
            if (count($emails) > 0) {
                $contactCont->updateMetaInfo($contact, 'email', $emails);
            }
            if (count($phones) > 0) {
                $contactCont->updateMetaInfo($contact, 'phone', $phones);
            }

            $contactCustomFields = $request->input('contactCustomFields');
            if (isset($contactCustomFields) && count($contactCustomFields) > 0) {
                CustomFieldValuesHelper::updateCustomFieldValues($contactCustomFields, $contact);
            }
        }

        $customFields = getItem($request->all(), 'customFields', []);
        if (isset($customFields) && count($customFields) > 0) {
            CustomFieldValuesHelper::updateCustomFieldValues($customFields, $org);
        }

        $org->dispatchCreatedEvents();

        $this->updateParent($org);

        $org->updateMeta(['taxExemptReasonId' => optional($data)['taxExemptReasonId'] ?? '']);

        // IntegrationSyncOrganizationJob::dispatch($org->id);

        return new OrganizationResource($org);
    }

    private function updateParent(Organization $org)
    {
        $parentId = request()->input('parentId');
        $billingType = request()->input('billingType');

        $org->update([
            'parent_id' => $parentId,
            'billing_type' => $billingType,
        ]);
    }

    public function postUpdate(Organization $org, Request $request)
    {
        return $this->patchIndex($org, $request);
    }

    public function patchIndex(Organization $org, Request $request)
    {
        $data = $request->validate([
            'name'              => 'required',
            'isVip'             => 'sometimes',
            'status'            => 'required',
            'brand'             => 'required',
            'owner'             => 'sometimes',
            'changeOwnership'   => 'sometimes',
            'termId'            => 'sometimes',
            'tierId'            => 'sometimes',
            'isTaxExempt'       => 'sometimes',
            'taxExemptReasonId' => 'sometimes',
            'currency'          => 'sometimes',
        ]);

        $org->update([
            'name'           => $data['name'],
            'is_vip'         => $data['isVip'],
            'status'         => $data['status'],
            'brand_id'       => $data['brand'],
            'owner_id'       => $data['owner'],
            'term_id'        => optional($data)['termId'] ?? 0,
            'tier_id'        => optional($data)['tierId'] ?? 0,
            'is_tax_exempt'  => optional($data)['isTaxExempt'] ?? 0,
            'currency'       => optional($data)['currency'] ?? Company::current()->currency,
        ]);

        $customFields = getItem($request->all(), 'customFields', []);
        CustomFieldValuesHelper::updateCustomFieldValues($customFields, $org);

        if (optional($data)['changeOwnership']) {
            $org->contact()->update([
                'owner_id' => $data['owner']
            ]);
            $org->deal()->update([
                'owner_id' => $data['owner']
            ]);
        }

        if ($org->status == '4') {
            Contact::where('org_id', $org->id)->update(['status' => '3']);
        } else if ($org->status == '6') {
            Contact::where('org_id', $org->id)->update(['status' => '4']);
        }

        $org->dispatchUpdatedEvents();

        $this->updateParent($org);

        $org->updateMeta(['taxExemptReasonId' => optional($data)['taxExemptReasonId'] ?? '']);
        if ($org->import_id) {

            IntegrationSyncOrganizationJob::dispatch($org->id);
        }

        return new OrganizationResource($org);
    }

    public function patchWebsites(Organization $org, Request $request)
    {
        $data = $request->validate([
            'websites' => 'sometimes',
        ]);

        $org->update([
            'websites'       => json_encode($data['websites']),
        ]);

        return new OrganizationResource($org);
    }

    public function deleteIndex(Organization $org)
    {
        if ($org->subscribe()->whereNull('unsub_at')->count() > 0) {
            return response()->json(['message' => 'Organization has active subscriptions, please cancel those subscriptions before deleting.'], 422);
        }
        $org->dispatchDeletedEvents();
        $org->contact->each(function ($contact) {
            $contact->meta()->delete();
        });
        $org->contact()->delete();
        $org->delete();
        return ['success' => true];
    }

    public function getOrganization($orgId)
    {
        $org = Organization::with([
            'address', 'brand', 'owner', 'user', 'contact',
            'contact.meta',
            'paymentMethod',
            'subscribe',
            'subscribe.invoice',
            'transactions',
            'parent',
            'children',
            'allProjects',
            'deal',
            'allProjects.projectInvoice',
            'allProjects.projectInvoice.line',
            'allProjects.projectInvoice.line.service',
            'invoices',
            'invoices.project',
            'invoices.subscribe',
            'invoices.line',
            // 'unpaidInvoices' => function ($inner) {
            //     $inner->whereHas('subscribe', function ($inner) {
            //         $inner->whereNull('unsub_at');
            //     });
            // }
        ])->findOrFail($orgId);

        return new OrganizationResource($org);
    }

    public function getCompactOrganization($orgId)
    {
        $org = Organization::with([
            'address', 'brand', 'owner', 'user', 'contact',
            'contact.meta',
        ])->findOrFail($orgId);

        return new CompactOrganizationResource($org);
    }


    public function postAddressCreate(Organization $org, Request $request)
    {
        $data = $request->validate([
            'address'       => 'required',
            'city'          => 'required',
            'state'         => 'sometimes',
            'postal'        => 'sometimes',
            'country'       => 'required',
            'isDefault'     => 'sometimes',
            'addressLineTwo' => 'sometimes',
            'type'          => 'sometimes',
            'name'          => 'sometimes',
            'notes'          => 'sometimes',
            'phone'          => 'sometimes',
            'contactId'      => 'sometimes',
        ]);
        $totalAddress =  $org->address()->count();
        if (optional($data)['isDefault'] != true && $totalAddress == 0) {
            $data['isDefault'] = 1;
        } elseif ($totalAddress > 0 && optional($data)['isDefault'] == true) {
            $org->address()->update(['is_default' => 0]);
        }

        $address = $org->address()->create([
            'address'   => $data['address'],
            'address_2' => optional($data)['addressLineTwo'],
            'city'      => $data['city'],
            'state'     => $data['state'],
            'postal'    => $data['postal'],
            'country'   => $data['country'],
            'name'      => optional($data)['name'],
            'phone'     => optional($data)['phone'],
            'notes'     => optional($data)['notes'],
            'contact_id'     => optional($data)['contactId'],
            'is_default'    => optional($data)['isDefault'] ?? 0,
            'type'          => optional($data)['type'] ?? 'billing',
        ]);
        return new OrganizationAddressResource($address);
    }

    public function postAddressDefault(OrganizationAddress $address)
    {
        $org = $address->organization;
        $org->address()->update(['is_default' => 0]);
        $address->is_default = 1;
        $address->update();
        return new OrganizationAddressResource($address);
    }

    public function deleteAddress(OrganizationAddress $address)
    {
        if ($address->is_default) {
            $org = $address->organization;
            $firstAddress = $org->address()->where('id', '!=', $address->id)->first();
            if (!$firstAddress) {
                abort(400, 'You can not delete the only address');
            } else {
                $firstAddress->update(['is_default' => 1]);
            }
        }
        $address->delete();
        return ['success' => true];
    }

    public function getAddresses(Request $request, $orgId)
    {
        $ids = $request->input('ids', []);
        $ids = !is_array($ids) ? [$ids] : $ids;

        $addresses = OrganizationAddress::withTrashed()->where('org_id', $orgId)
            ->where(function ($inner) use ($ids) {
                $inner->where(function ($subQuery) use ($ids) {
                    $subQuery->whereIn('id', $ids);
                });
                $inner->orWhereNull('deleted_at');
            })->get();

        return OrganizationAddressResource::collection($addresses);
    }

    public function patchAddress(OrganizationAddress $address, Request $request)
    {
        $data = $request->validate([
            'address'        => 'required',
            'city'           => 'required',
            'state'          => 'sometimes',
            'postal'         => 'sometimes',
            'country'        => 'required',
            'isDefault'      => 'sometimes',
            'addressLineTwo' => 'sometimes',
            'type'           => 'sometimes',
            'name'           => 'sometimes',
            'notes'          => 'sometimes',
            'phone'          => 'sometimes',
            'contactId'      => 'sometimes',
        ]);

        $address->update([
            'address'    => $data['address'],
            'address_2'  => optional($data)['addressLineTwo'],
            'city'       => $data['city'],
            'state'      => $data['state'],
            'postal'     => $data['postal'],
            'country'    => $data['country'],
            'name'       => optional($data)['name'],
            'phone'      => optional($data)['phone'],
            'notes'      => optional($data)['notes'],
            'contact_id'      => optional($data)['contactId'],
            'is_default' => optional($data)['isDefault'] ?? 0,
            'type'       => optional($data)['type'] ?? 'billing',
        ]);

        return new OrganizationAddressResource($address);
    }

    public function postConvertProject(Organization $organization, Request $request)
    {
        $data = $request->validate([
            'transactionId'  => 'required',
            'title'          => 'required',
            'description'    => 'sometimes',
            'contact'        => 'required',
            'salesRep'       => 'sometimes',
            'invoiceDate'    => 'required',
            'dueDate'        => 'required',
            'board'          => 'required',
            'stage'          => 'required',
            'billingEmail'   => 'required',
            'invoiceDate'    => 'required',
            'billingAddress' => 'required',
            'hours'          => 'sometimes'
        ]);


        $startDate = Carbon::createFromTimestampUTC($data['invoiceDate'])->format('Y-m-d');
        $endDate = Carbon::createFromTimestampUTC($data['dueDate'])->format('Y-m-d');

        $transaction = Transaction::findOrFail($data['transactionId']);
        $invoice = $transaction->invoice;

        if (!$invoice) {
            throw new \Exception("Transaction should have an invoice");
        }

        $project              =  $organization->projects()->create([
            'title'           => $data['title'],
            'description'     => $data['description'],
            'hours'           => optional($data)['hours'] ?? 0,
            'account_manager' => optional($data)['accountManager'] ?? 0,
            'sale_rep'        => optional($data)['salesRep'],
            'board_id'        => $data['board'],
            'stage_id'        => $data['stage'],
            'contact_id'      => $data['contact'],
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'company_id'      => Company::id(),
            'created_by'      => Auth::id(),
            'template_id'     => 0,
            'amount'          => $invoice->total_amount,
            'balance'         => $invoice->balance,
        ]);

        $invoice->transaction()->update([
            'project_id' => $project->id,
            'type' => 'project',
        ]);

        $invoice->update([
            'type' => 'project',
            'object_id' => $project->id,
        ]);


        return new OrganizationResource($organization);
    }

    public function postSync(Organization $org)
    {
        if (!empty($org->import_id)) {
            $org->syncService();
        }

        return new OrganizationResource($org);
    }

    public function postManualSupportHours(Request $request, Organization $org)
    {
        $data = $request->validate([
            'expiredAt' => 'required',
            'supportHours' => 'required',
            'subId' => 'required',
        ]);

        if ($data['subId'] == 0) {
            $data['subId'] = $org->subscribe()->value('id');
            if ($data['subId'] == null) {
                abort(400, 'This organization is not subscribed to any plan');
            }
        }


        $subscription = OrganizationSubscription::find($data['subId']);
        $firstInvoice = $subscription->invoices()->orderBy('id', 'desc')->first();

        $hour = SubscriptionSupportHour::create([
            'org_id' => $org->id,
            'sub_id' => (int)$request->input('subId'),
            'invoice_id' => $firstInvoice->id,
            'seconds' => ((int)$request->input('supportHours')) * 60 * 60,
            'started_at' => Carbon::now()->toDateString(),
            'expired_at' => Carbon::createFromTimestampUTC($request->expiredAt)->toDateString(),
            'notes' => $request->input('notes')
        ]);

        SubscriptionSupportHour::adjustExcessHours($hour);

        return ['success' => true];
    }

    public function getSupportHourInfo(Organization $org)
    {
        return [
            'supportTime' => $org->getSupportHours(),
            'trackedTime' => $org->getTrackedTime(),
            'hoursBreakup' => $org->getHoursBreakup(),
        ];
    }

    public function postSyncTrans(Organization $org)
    {
        $org->syncTransactions();

        return new OrganizationResource($org);
    }

    public function postChangeOwner(Request $request)
    {
        $data = $request->validate([
            'userId' => 'required',
            'orgIds' => 'required',
            'changeOwnership' => 'sometimes',
        ]);

        $orgs = Organization::whereIn('id', $data['orgIds'])->get();
        foreach ($orgs as $org) {
            $org->update([
                'owner_id' => $data['userId']
            ]);
        }
        // Organization::whereIn('id', $data['orgIds'])->update(['owner_id' => $data['userId']]);
        if (optional($data)['changeOwnership']) {
            Organization::whereIn('id', $data['orgIds'])->get()->each(function ($org) use ($data) {

                $org->contact()->update([
                    'owner_id' => $data['userId']
                ]);
                $org->deal()->update([
                    'owner_id' => $data['userId']
                ]);
            });
        }
        return ['status' => true];
    }

    public function postDeleteMultiple(Request $request)
    {
        $data = $request->validate([
            'orgIds' => 'required',
        ]);

        $orgs = Organization::whereIn('id', $data['orgIds'])->get();
        $activeSubscriptions = OrganizationSubscription::where('org_id', $data['orgIds'])->whereNull('unsub_at')->get();
        if ($activeSubscriptions->count() > 0) {
            $orgsWithSubIds = $activeSubscriptions->pluck('org_id');
            $orgsWithSubs = Organization::whereIn('id', $orgsWithSubIds)->pluck('name')->join(', ');
            $hasHave = count($orgsWithSubIds) > 1 ? "have" : "has";
            return response()->json(['message' => "$orgsWithSubs $hasHave active subscriptions, please cancel them before deleting organiation."], 422);
        }
        foreach ($orgs as $org) {
            $org->delete();
        }


        return ['status' => true];
    }

    public function postMerge(Request $request)
    {
        $data = $request->validate([
            'orgIds' => 'required',
            'primary' => 'required',
        ]);

        $org = Organization::find($data['primary']);
        $org->merge(collect($data['orgIds'])->filter(fn ($item) => $item != $data['primary'])->values());

        return ['status' => true];
    }

    public function getClientOrgs()
    {
        $query = DocumentFolder::select('org_id')->where('company_id', Company::id())
            ->client()
            ->groupBy('org_id');

        $query->union(DocumentFolderFile::select('org_id')->where('company_id', Company::id())
            ->client()
            ->groupBy('org_id'));

        $orgIds = $query->pluck('org_id')->toArray();

        return CompactOrganizationResource::collection(Organization::whereIn('id', $orgIds)->get());
    }

    public function getVendastaAccounts(Request $request)
    {
        $pages = Cache::get(cacheKey('vendastaPages'), 0);
        $data = collect();
        for ($i = 1; $i <= $pages; $i++) {
            $data = $data->concat(collect(json_decode(Cache::get(cacheKey('vendastaPage' . $i), '[]'), true)));
        }
        // $data = Cache::get(cacheKey('vendasta'), '[]');
        return collect(json_decode($data, true))->filter(function ($item) use ($request) {
            return Str::contains(strtolower($item['companyName']), strtolower($request->input('search')));
        })->map(function ($item) {
            return [
                'companyName' => $item['companyName'],
                'accountGroupId' => $item['accountGroupId'],
            ];
        })->sortBy(function ($item) {
            return $item['companyName'];
        })->values();
    }

    public function postVendastaAccount(Organization $org, Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'name' => 'required',
        ]);

        if ($data['id'] == 'remove' && $data['name'] === 'remove') {
            $extra = json_decode($org->extra ?? '[]', true);
            $extra['accountGroupId'] = null;

            $org->update([
                'vendasta_id' => null,
                'vendasta_name' => null,
                'extra' => json_encode($extra),
            ]);
        } else {
            $extra = json_decode($org->extra ?? '[]', true);
            $extra['accountGroupId'] = $data['id'];

            $org->update([
                'vendasta_id' => $data['id'],
                'vendasta_name' => $data['name'],
                'extra' => json_encode($extra),
            ]);
        }

        return new CompactOrganizationResource($org);
    }

    public function postSendInvoice(TransactionInvoice $invoice)
    {
        $invoice->send();
        return ['status' => true];
    }

    public function postVoidInvoice(TransactionInvoice $invoice)
    {
        $invoice->voidInvoice();
        return new OrganizationResource($invoice->organization);
    }

    public function postProcessInvoice(TransactionInvoice $invoice, Request $request)
    {
        $methodId = $request->input('methodId');
        $method = PaymentMethod::findOrFail($methodId);
        $invoice->processPaymentViaQB($invoice->balance, $method->token);
        return new OrganizationResource($invoice->organization);
    }

    public function postChild(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'childId' => 'required',
            'billingType' => 'required',
        ]);

        Organization::where('id', $data['childId'])->update([
            'parent_id' => $organization->id,
            'billing_type' => $data['billingType'],
        ]);

        IntegrationSyncOrganizationJob::dispatch($organization->id, false);
        IntegrationSyncOrganizationJob::dispatch($data['childId'], false);

        return new OrganizationResource($organization);
    }

    public function deleteChild(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'childId' => 'required',
        ]);

        Organization::where('id', $data['childId'])->update([
            'parent_id' => 0,
            'billing_type' => 'self',
        ]);

        IntegrationSyncOrganizationJob::dispatch($organization->id, false);
        IntegrationSyncOrganizationJob::dispatch($data['childId'], false);

        return new OrganizationResource($organization);
    }

    public function postExport(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes',
            'ownerId' => 'sometimes',
            'status' => 'sometimes',
            'hasDeal' => 'sometimes',
            'isEscalate' => 'sometimes',
            'requestId' => 'sometimes',
            'brand' => 'sometimes',
            'tags' => 'sometimes',
            'tagIds' => 'sometimes',
        ]);

        ExportOrganizationJob::dispatch(auth()->id(), 'exportOrganizations', Company::id(), $data, $request->all())->onQueue('export');
        return ['status' => true];
    }

    public function getDownloadExport(JobMonitor $job)
    {
        $job->update(['dismissed' => true]);
        return $job->output;
    }

    public function postMergeContacts(Organization $org, Request $request)
    {
        $data = $request->validate([
            'contacts' => 'required',
            'primary' => 'required',
        ]);

        $contact = Contact::find($data['primary']);
        $contact->merge(collect($data['contacts'])->filter(fn ($item) => $item != $data['primary'])->values());

        return new OrganizationResource($org);
    }

    public function getSnapshot(Request $request)
    {
        $id = $request->input('id');
        $orgId = $request->input('orgId');
        $client = new VendastaClient();
        $response = $client->getSnapshotReportUrl($id);
        $path = getItem($response, 'snapshot.path');
        if ($path) {
            return redirect("https://sales.studio98.com/" . $path);
        } else {
            $org = Organization::find($orgId);
            $data = $org->extra_data;
            $url = $data['reportUrlOriginal'];
            return redirect($url);
        }
    }
}
