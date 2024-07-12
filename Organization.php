<?php

namespace App\Models;

use App\Activities\ActivityModule;
use App\Activities\Modules\ActivityLog;
use App\Activities\Traits\HasActivity;
use App\Events\ContactOrg\ContactOrgCreated;
use App\Events\ContactOrg\ContactOrgDeleted;
use App\Events\ContactOrg\ContactOrgUpdated;
use App\Events\Organization\OrganizationCreated;
use App\Events\Organization\OrganizationDeleted;
use App\Events\Organization\OrganizationUpdated;
use App\Helpers\HtmlContentHelper;
use App\Http\Resources\Webhook\OrganizationWebhookResource;
use App\Http\Resources\Webhook\WISEOrganizationWebhookResource;
use App\Jobs\IntegrationSyncInvoiceJob;
use App\Services\CRMService\QuickBooks\QBWebhooksService;
use App\Services\ImportCrmService\Facades\ImportCrmService;
use App\Services\ImportCrmService\ImportCrmServiceInterface;
use App\Tenancy\TenantManager;
use App\Traits\CompanyQueryTrait;
use App\Traits\HasTags;
use App\Traits\MetaTrait;
use App\Traits\OnObserveTrait;
use App\Traits\PermissionQueryTrait;
use App\Traits\TriggersWebhook;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Jobs\SurveyMonkeyContactJob;

class Organization extends Model
{
    use HasFactory, SoftDeletes, CompanyQueryTrait, HasActivity, PermissionQueryTrait, OnObserveTrait, TriggersWebhook, MetaTrait, HasTags;

    public $table = 'organizations';
    public $webhookEvent = 'organization';

    protected $guarded = [];

    protected $dates = ['last_activity'];

    protected $watch = ['owner_id', 'account_manager'];
    protected $observe = ['hotness'];

    protected $module = 'organizations';

    protected $permissionField = 'owner_id';

    public static function saveLastActivity(self $instance)
    {
        \DB::table($instance->getTable())
            ->where('id', $instance->id)
            ->update([
                'last_activity' => Carbon::now()->toDateTimeString(),
            ]);
    }

    public static $STATUSES = [
        2 => 'Lead',
        1 => 'Customer',
        3 => 'Past Customer',
        4 => 'Do Not Contact',
        5 => 'Spam',
        6 => 'Do not Sell',
        7 => 'Not Interested',
        8 => 'Out of Business',
    ];

    public static function getStatusByName($name)
    {
        $name = strtolower($name);
        if ($name == 'lead') {
            return 2;
        } else if ($name == 'customer') {
            return 1;
        } else if ($name == 'past customer') {
            return 3;
        } else if ($name == 'do not contact') {
            return 4;
        } else if ($name == 'spam') {
            return 5;
        } else if ($name == 'do not sell') {
            return 6;
        } else if ($name == 'not interested') {
            return 7;
        } else if ($name == 'out of business') {
            return 8;
        } else {
            return 2;
        }
    }

    public static function getStatusById($id)
    {
        if (optional(self::$STATUSES)[$id]) {
            return self::$STATUSES[$id];
        }

        return $id;
    }

    public function OrgStatus()
    {
        return $this->belongsTo(OrgStatus::class, 'status');
    }

    public function getOrgStatusAttribute()
    {
        $status = OrgStatus::whereId($this->status)->first();
        return $status;
    }

    public function getAttribute($key)
    {
        return parent::getAttribute($key);
    }

    public function firstContact()
    {
        return $this->hasOne(Contact::class, 'org_id')->withTrashed();
    }

    public function contact()
    {
        return $this->hasMany(Contact::class, 'org_id')->orderBy('first_name');
    }

    public function address()
    {
        return $this->hasMany(OrganizationAddress::class, 'org_id');
    }

    public function importedContact()
    {
        return $this->hasOne(Contact::class, 'org_id')->whereNotNull('import_id');
    }

    public function defaultAddress()
    {
        return $this->hasOne(OrganizationAddress::class, 'org_id')->orderBy(\DB::raw('CASE WHEN is_default = 1 THEN 1 ELSE 2 END'), 'asc');
    }

    public function defaultPaymentMethod()
    {
        return $this->hasOne(PaymentMethod::class, 'org_id')->orderBy(\DB::raw('CASE WHEN is_default = 1 THEN 1 ELSE 2 END'), 'asc');
    }

    public function paymentMethod()
    {
        return $this->hasMany(PaymentMethod::class, 'org_id');
    }

    public function projects()
    {
        return $this->hasMany(OrganizationProject::class, 'org_id');
    }

    public function allProjects()
    {
        return $this->hasMany(OrganizationProject::class, 'org_id')->withTrashed();
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'account_manager')->withTrashed();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'org_id')->orderBy('payment_time', 'desc')->orderBy('id', 'desc');
    }

    public function subscribe()
    {
        return $this->hasMany(OrganizationSubscription::class, 'org_id');
    }

    public function activeSubscribtion()
    {
        return $this->hasMany(OrganizationSubscription::class, 'org_id')->where('unsub_at', null);
    }

    public function marketBoardNote()
    {
        return $this->hasMany(MarketBoardNote::class, 'org_id')->orderBy('created_at', 'desc');
    }

    public function tickets()
    {
        return $this->hasMany(SupportTicket::class, 'org_id');
    }

    public function invoices()
    {
        return $this->hasMany(TransactionInvoice::class, 'org_id')->where('is_template', 0);
    }

    public function unpaidInvoices()
    {
        return $this->hasMany(TransactionInvoice::class, 'org_id')
            ->whereIn('type', ['one-time-sub', 'subscription', 'initial-subscription', 'order'])
            ->whereDate('invoice_date', '<=', now()->format('Y-m-d'))
            ->where('is_template', '!=', 1)
            ->whereNotNull('import_id')
            // ->where(function ($query) {
            //     $query->where(function ($subQ) {
            //         $subQ->whereNotNull('import_data')->where('type', 'subscription');
            //     });
            //     $query->orWhereIn('type', ['one-time-sub', 'initial-subscription']);
            // })
            ->where('balance', '>', 0);
    }


    public function marketBoardDueDate()
    {
        return $this->hasOne(MarketBoardSetting::class, 'org_id')
            ->where('key', '_checklist_due_date')
            ->orderBy(DB::raw('IFNULL(DATE(value), "2050-12-31")'), 'ASC');
    }

    public function marketBoardStatus()
    {
        //WHEN market_board_settings.value = "canceled" THEN 1
        return $this->hasOne(MarketBoardSetting::class, 'org_id')
            ->where('key', 'board_status')
            ->orderBy(\DB::raw('CASE
                WHEN market_board_settings.value = "overdue" THEN 4
				WHEN  market_board_settings.value = "pending_delivery" THEN 3
				WHEN  market_board_settings.value = "active" THEN 2
				WHEN  market_board_settings.value = "delivered" THEN 0
			    ELSE 1
		    END'), 'DESC');
    }

    public function boardFeedback()
    {
        return $this->hasOne(MarketBoardSetting::class, 'org_id')
            ->where('key', '_feedback')
            ->orderBy('created_at', 'DESC');
    }

    public function marketBoardProgress()
    {
        return $this->hasMany(MarketBoardSetting::class, 'org_id')
            ->where('key', 'cl_progress');
    }

    public function deal()
    {
        return $this->hasMany(Deal::class, 'org_id');
    }

    public function activeDeal()
    {
        return $this->hasMany(Deal::class, 'org_id')->where('status', 'in_progress');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function marketingCallAction()
    {
        return $this->hasMany(ContactAction::class, 'object_id')
            ->whereIn('activity_type', ['meeting', 'note'])
            ->whereHas('reference', function ($query) {
                $startTime = Carbon::now()->startOfMonth()->format('Y-m-d H:i:s');
                $endTime = Carbon::now()->endOfMonth()->format('Y-m-d H:i:s');
                return $query->where('is_marketing_board', 1)->whereBetween('for_date', [$startTime, $endTime]);
            });
    }

    public function syncService()
    {
        $integration = $this->company->accounting()->where('type', $this->import_type)->first();
        if (!$integration) {
            return false;
        }

        $crmService = $integration->getService();
        if (!$crmService)
            return false;

        $customer = $crmService->getOrganization($this->import_id);
        if (!optional($customer)['id']) {
            return false;
        }

        /*$items = $crmService->getOrgSubscriptions($this->import_id);
		dd($items);*/

        $importService = ImportCrmService::service($this->company_id, $this->brand_id, $this->import_type);
        $importService->syncCustomers([$customer]);


        $methods = $crmService->getPaymentMethods($this->import_id);
        $importService->syncPaymentMethods($methods);

        $mImportIds = collect($methods)->pluck('id')->toArray();
        $iImportIds = $importService->syncCustomerInvoices($this->import_id);
        $pImportIds = $importService->syncTransactions($this->import_id);

        $this->transactions()->where('import_type', $this->import_type)
            ->where('import_id', '!=', '')
            ->whereNotNull('import_id')
            ->whereNotIn('import_id', $pImportIds)
            ->delete();

        TransactionInvoice::where('import_type', $this->import_type)
            ->where('org_id', $this->id)
            ->where('object_id', 0)
            ->where('import_id', '!=', '')
            ->whereNotNull('import_id')
            ->whereNotIn('import_id', $iImportIds)
            ->delete();

        $this->paymentMethod()->whereNotNull('token')
            ->whereNotIn('token', $mImportIds)
            ->where('service', $this->import_type)
            ->delete();

        return true;
    }

    public function syncTransactions()
    {
        $integration = $this->company->accounting()->where('type', $this->import_type)->first();
        if (!$integration) {
            return false;
        }

        $service = $integration->getService();
        if (!$service) {
            return false;
        }

        $transactions = $service->client->payments()->getByCustomer($this->import_id);

        foreach ($transactions as $trans) {
            (new QBWebhooksService)->setCompanyId($this->company_id)->setBrandId($this->brand_id)->setUserId(SiteSetting::systemRepId())->createPayment($trans['id'], $service);
        }
    }

    public function syncOnlyTransactions()
    {
        $integration = $this->company->integrations()->where('type', $this->import_type)->first();
        if (!$integration) {
            return false;
        }

        $service = $integration->getService();
        if (!$service) {
            return false;
        }

        $transactions = $service->client->payments()->getByCustomer($this->import_id);

        foreach ($transactions as $trans) {
            (new QBWebhooksService)->setCompanyId($this->company_id)->setBrandId($this->brand_id)->setUserId(SiteSetting::systemRepId())->syncPayment($trans['id'], $service);
        }
    }

    public function getApiInput()
    {
        $full_name  = $this->name;
        $input = [
            'companyName' => trim($full_name),
            'name' => trim($full_name),
            'importId'    => $this->import_id,
            'currency'    => $this->currency,
        ];


        $contact = $this->contact()->orderBy('import_id', 'desc')->first();
        if ($contact) {
            $input['firstName'] = $contact->first_name;
            $input['lastName']  = $contact->last_name;

            $email = '';
            $emails = [];
            foreach ($contact->meta()->where('key', 'email')->select('value')->get() as $info) {
                $emails[] = $info->value;
            }
            if (count($emails) > 0) {
                $email = implode(',', $emails);
            }
            if (!empty($email)) {
                $input['email'] = $email;
            }
            $phones = [];
            foreach ($contact->meta()->where('key', 'phone')->select('value', 'type')->get() as $info) {
                $phones[$info->type] = $info->value;
            }
            if (count($phones) > 0) {
                $input['phone'] = $phones;
            }
        }

        $address = $this->address()
            ->orderBy('is_default', 'desc')->first();
        if ($address) {
            $input['address'] = [
                'address'   => $address->address,
                'address_2' => $address->address_2,
                'city'      => $address->city,
                'state'     => $address->state,
                'postal'    => $address->postal,
                'country'   => $address->country,
            ];
        }

        $input['parentId'] = optional($this->parent)->import_id ?? '';
        $input['billingType'] = $this->billing_type;
        $input['isTax'] = $this->is_tax_exempt == 1 ? false : true;
        $input['taxExemptReasonId'] = $this->findMeta('taxExemptReasonId') ?? '';

        return $input;
    }

    public function quickBooksSync($isFull = true)
    {
        $input        = $this->getApiInput();
        $contact      = $this->contact()->orderBy('import_id', 'desc')->first();
        $company      = Company::findOrFail($this->company_id);
        $integrations = $company->accounting;

        foreach ($integrations as $integration) {
            $service = $integration->getService();
            if (!empty($this->import_id)) {
                $service->onUpdateOrganization($input);
            } else {
                $customer = $service->onCreateOrganization($input);
                $this->update([
                    'import_id'     => $customer,
                    'import_type'   => $integration->type,
                ]);
                if ($contact) {
                    $contact->update([
                        'import_id' => $customer,
                        'import_type' => $integration->type,
                    ]);
                }
            }
        }
        if ($contact and $contact->import_id == 0) {
            $contact->update([
                'import_id'     => $this->import_id,
                'import_type'   => $this->import_type,
            ]);
        }

        /*
         * Sync Invoices
         */

        if (!empty($this->import_id) && $isFull) {
            $invoices = TransactionInvoice::where('org_id', $this->id)
                ->whereNull('import_id')->select('id')
                ->get();
            foreach ($invoices as $invoice) {
                IntegrationSyncInvoiceJob::dispatchNow($invoice->id);
            }
        }
    }

    public function scopeImported(Builder $query, $service, $importId = 0)
    {
        if ($service == 'quickbooks') {

            $query->where('import_type', $service)
                ->where('import_id', '>', 0);

            if (!empty($importId)) {
                $query->where('import_id', $importId);
            }
        } else if ($service == 'stripe') {
            $query->whereNotNull('payment_id');
            if (!empty($importId)) {
                $query->where('payment_id', $importId);
            }
        }

        return $query;
    }

    public function scopeMarketBoard(Builder $builder)
    {
        return $builder->where('is_market_board', 1);
    }

    public function isMarketBoard()
    {
        return $this->is_market_board == 1;
    }

    public function leadSourceField()
    {
        return $this->hasOne(CustomFieldValue::class, 'object_id')->whereHas('field', function ($inner) {
            $inner->where('for', 'organizations');
            $inner->where('label', 'Lead Source');
        });
    }

    public function wixField()
    {
        return $this->hasOne(CustomFieldValue::class, 'object_id')
            ->where('value', 1)
            ->whereHas('field', function ($inner) {
                $inner->where('for', 'organizations');
                $inner->where('label', 'Lead Source');
            });
    }

    public function customFieldValue()
    {
        return $this->hasMany(CustomFieldValue::class, 'object_id')->whereHas('field', function ($inner) {
            $inner->where('for', 'organizations');
        });
    }

    public function supportTime()
    {
        return $this->hasMany(SubscriptionSupportHour::class, 'org_id');
    }

    public function getSupportHours()
    {
        return SubscriptionSupportHour::active()->where("org_id", $this->id)->get()->reduce(function ($carry, $item) {
            return $carry + $item->seconds;
        }, 0);
        // $extraTime = SubscriptionSupportHourLog::where("org_id", $this->id)->where('hour_id', 0)->sum('seconds');
    }

    public function getTrackedTime()
    {
        $remainingTime = SubscriptionSupportHour::active()->where("org_id", $this->id)->get()->reduce(function ($carry, $item) {
            return $carry + $item->tracked;
        }, 0);
        $extraTime = SubscriptionSupportHourLog::where("org_id", $this->id)->where('hour_id', 0)->sum('seconds');

        return $remainingTime + $extraTime;

        // $startDate = Carbon::now()->format('Y-m-1');
        // $ticketIds = SupportTicket::where('org_id', $this->id)->pluck('id');

        // $subscribe = $this->subscribe()->whereNull('unsub_at')->orderBy(DB::raw('MONTH(start_date)'), 'asc')->orderBy(DB::raw('DAY(start_date)'), 'asc')->first();
        // if ($subscribe) {
        //     $startDay = Carbon::parse($subscribe->start_date)->format('d');
        //     $startDate = Carbon::now()->subMonth()->format('Y-m-' . $startDay);
        //     if (optional($subscribe->invoice)->id) {
        //         $startDate = Carbon::parse($subscribe->invoice->invoice_date)->format('Y-m-d');
        //     }
        // }

        // $endDate = Carbon::now()->toDateString();

        // $time = TimeLog::query()->where('type', 'ticket')->whereIn('object_id', $ticketIds)
        //     ->whereDate('for_date', '>=', $startDate)
        //     ->whereDate('for_date', '<=', $endDate)
        //     ->sum('time');

        // return (int)$time;
    }

    public function getHoursBreakup()
    {
        $activeHours =  SubscriptionSupportHour::active()->where("org_id", $this->id)->get()->map(function ($item) {
            return [
                'id'        => strval($item->id),
                'title'     => optional($item->subscription)->title,
                'total'     => $item->seconds,
                'used'      => $item->seconds - $item->remaining,
                'remaining' => $item->remaining,
                'expiresAt' => $item->is_rollover ? -1 : Carbon::parse($item->expired_at)->timestamp,
            ];
        });

        $extraTime = SubscriptionSupportHourLog::where("org_id", $this->id)->where('hour_id', 0)->sum('seconds');
        if ($extraTime > 0) {

            $activeHours->push([
                'id'        => 'extra-time',
                'title'     => 'Extra Time',
                'total'     => $extraTime,
                'used'      => $extraTime,
                'remaining' => 0,
                'expiresAt' => 0,
            ]);
        }

        return $activeHours;
    }


    function onCreate($model)
    {
        $user = $model->user->full_name;
        $org = $model->name;
        return ActivityLog::contacts()
            ->title("$user has added $org as a new organization.")
            ->links([$org => "/organizations/{$model->id}"])
            ->bold([$user])
            ->type('organization.created');
    }

    function onDelete($model)
    {
        $user = $model->user->full_name;
        $user = auth()->user()->full_name;
        $org = $model->name;
        return ActivityLog::contacts()
            ->title("$user has removed organization $org")
            ->bold([$user, $org])
            ->type('organization.deleted');
    }

    function onUpdate($model)
    {
        $user = Auth::user()->full_name;
        $org = $model->name;
        $dirty = $model->getDirty();
        $logged = false;

        if (optional($dirty)['account_manager']) {
            ActivityLog::contacts()
                ->title("$user has assigned {$org} subscriptions to {$model->manager->full_name}.")
                ->bold([$user, $model->manager->full_name])
                ->links([
                    $org => "/organizations/{$model->org_id}",
                ])
                ->type('organization.updated')
                ->updated();
            $logged = true;
        }


        if (!$logged) {
            return ActivityLog::contacts()
                ->title("$user has changed the owner of $org to {$model->owner->full_name}")
                ->links([$org => "/organizations/{$model->id}"])
                ->bold([$user, $model->owner->full_name])
                ->type('organization.updated');
        }

        return null;
    }

    public function getStatusNameAttribute($value)
    {
        $name = array_search($value, self::getStatuses());

        return !empty($nam) ? $name : $value;
    }

    public static function statusHandler(Organization $org)
    {
        $transactions = $org->transactions()->count();
        if ($org->status == 2 && $transactions > 0) {
            $org->status = 1;
            $org->update();
        }
    }

    public static function getStatuses()
    {
        return OrgStatus::all()->map(function ($item) {
            return [$item->name => $item->id];
        })->toArray();
        // return [
        //     'customer' => 1,
        //     'lead' => 2,
        //     'pastCustomer' => 3,
        //     'dnd' => 4,
        //     'spam' => 5,
        //     'dns' => 6,
        // ];
    }

    public function getExtraDataAttribute()
    {
        if (empty($this->extra)) {
            return [];
        }

        $data = json_decode($this->extra, true);

        return [
            'hotness' => optional($data)['hotness'] ?? 0,
            'reportUrl' => optional($data)['reportUrl'] && optional($data)['accountGroupId']  ? url('/' . TenantManager::id() . '/vendasta/snapshot?id=' . $data['accountGroupId']) . '&orgId=' . $this->id : null,
            'reportUrlOriginal' => optional($data)['reportUrl'] ?? null,
            'lastActivityDateTime' => optional($data)['lastActivityDateTime'] ?? null,
            'lastActivityDetail' => optional($data)['lastActivityDetail'] ?? null,
            'accountGroupId' => optional($data)['accountGroupId'] ?? null,
        ];
    }

    public function onObserve($model)
    {
        if ($model->isDirty('hotness')) {
            $new = $model->hotness;
            $old = $model->getOriginal('hotness');
            $org = $model->name;
            $model->owner->message('hotness', ['id' => $model->id, 'name' => $org, 'old' => $old, 'new' => $new]);

            ActivityLog::contacts()
                ->title("$org hotness changed from $old to $new.")
                ->links([$org => "/organizations/{$model->id}"])
                ->type('organization.updated')
                ->updated();
            if ($model->deal()->count() == 0 && $model->contact()->first() && $old == 0 && $new > 0) {
                $dealBoard = DealBoard::first();
                $deal = $model->deal()->create([
                    'contact_id'      => $model->contact()->first()->id,
                    'company_id' => $model->company_id,
                    'name'            => $org,
                    'value'           => 0,
                    'stage_id'        => $dealBoard->stage()->first()->id,
                    'board_id'        => $dealBoard->id,
                    'owner_id'        => $model->owner_id,
                    'interval'        => 'one time',
                    'user_id'         => $model->owner_id,
                ]);
            }
        }
    }

    public function onWebhook()
    {
        if (TenantManager::isWISE()) {
            return new WISEOrganizationWebhookResource($this);
        }

        return new OrganizationWebhookResource($this);
    }

    public function toFillForm(array $fields, $formTitle = '')
    {
        $content = HtmlContentHelper::toHtmlForm($fields);
        $message = "Contact filled a form $formTitle. <br />" . $content;
        $this->createActivity($message, 'Note');
    }

    public function createActivity(string $message, $type = 'Note')
    {
        $contact = $this->contact()->first();

        $userId = !empty(auth()->id()) ? auth()->id() : SiteSetting::systemRepId();

        if (!optional($contact)->id) {
            return;
        }

        $conversation       =  ContactConversation::create([
            'contact_id'    => $contact->id,
            'message'       => $message,
            'html_message'  => $message,
            'notes_result'  => $type,
            'object_type'   => 'contact',
            'for_date'      => now()->toDateString(),
            'activity_type' => strtolower($type),
        ]);

        ContactAction::create([
            'contact_id'    => $contact->id,
            'object'        => $conversation->id,
            'activity_type' => strtolower($type),
            'created_by'    => $userId,
            'object_id'     => $conversation->id,
            'object_type'   => 'contact',
        ]);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'org_id');
    }

    public function merge($orgIds)
    {
        $primary = $this->id;
        $contactIds = Contact::whereIn('org_id', $orgIds)->pluck('id');
        CallSMSLog::whereIn('contact_id', $contactIds)->update(['org_id' => $primary]);
        Contact::whereIn('id', $contactIds)->update(['org_id' => $primary]);
        Deal::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        DocumentFolderFile::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        DocumentFolder::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        OrganizationAddress::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        OrganizationProject::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        OrganizationSubscription::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        PaymentMethod::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        SubscriptionSupportHour::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        SubscriptionSupportHourLog::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        SupportTicket::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        Task::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        TransactionInvoice::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        Transaction::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        MarketBoardNote::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);
        MarketBoardSetting::whereIn('org_id', $orgIds)->update(['org_id' => $primary]);

        self::whereIn('id', $orgIds)->delete();
    }

    public function getInvoiceSettingsAttribute(): array
    {
        $companyId = $this->company_id;
        $brandId = $this->brand_id;

        return InvoiceSetting::getSettings($companyId, $brandId);
    }

    public function getIsImportedAttribute()
    {
        return $this->import_type == 'quickbooks';
    }

    public function dispatchCreatedEvents()
    {
        foreach ($this->contact as $contact) {
            event(new ContactOrgCreated($contact));
        }

        event(new OrganizationCreated($this));
    }

    public function dispatchDeletedEvents()
    {
        foreach ($this->contact as $contact) {
            event(new ContactOrgDeleted($contact));
        }

        event(new OrganizationDeleted($this));
    }

    public function dispatchUpdatedEvents()
    {
        if ($this->wasChanged(['name', 'status'])) {
            foreach ($this->contact as $contact) {
                event(new ContactOrgUpdated($contact));
            }
        }

        event(new OrganizationUpdated($this));
    }

    public function getPaymentMethodUrlAttribute()
    {
        $q = base64_encode(http_build_query([
            'appId'        => TenantManager::id(),
            'companyId'    => $this->company_id,
            'orgId'        => $this->id,
        ]));

        return url('/payment-method/add?e=q&q=' . $q);
    }

    // TODO: Arham
    public function exchangeRate()
    {
        $currency = $this->currency;
        $rate = CompanyCurrency::where(['company_id' => $this->company_id, 'currency' => $currency])->first();
        if (isset($rate)) return (float)$rate->rate;
        return 1;
    }

    public function getCurrencyAttribute($currency)
    {
        if (empty($currency)) {
            $currency = $this->company->currency_code;
        }

        return $currency;
    }
}
