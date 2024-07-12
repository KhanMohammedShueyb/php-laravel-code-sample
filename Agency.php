<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use DB;
use Carbon\Carbon;

class Agency extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'agencies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
    	'name',
        'address',
        'latitude',
        'longitude',
        'phone',
        'email',
        'contact_name',
        'contact_phone',
        'contact_email',
        'why_contact',
        'approved',
        'logo_extension',
        'background_extension',
        'stage',
        'slug',
        'website',
        'facebook',
        'twitter',
        'linkedin',
        'pinterest',
        'instagram'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    protected $appends = ['phone_display','contact_phone','graph_data'];

    /**
     * Get the route key for the model
     *
     * @return string
     */
    
    public function getRouteKeyName()
    {
        return 'slug';
    }
    
    public function additionalStages() {
        return $this->belongsToMany('App\Stage', 'agencies_stages');
    }

    public function counties()
    {
        return $this->belongsToMany('App\County', 'agency_counties')->where('active',1);
    }

    public function locations() {
        return $this->belongsToMany('App\Location', 'agency_locations');
    }

    public function entrepreneurs()
    {
        return $this->belongsToMany('App\Entrepreneur', 'agency_entrepreneurs')->where('approved',1);
    }

    public function events()
    {
        return $this->hasMany('App\Event');
    }

    public function view()
    {
        DB::table('views')->insert(['agency_id' => $this->id, 'created_at' => Carbon::now()]);
    }

    public function sponsors()
    {
        return $this->hasMany('App\Sponsor');
    }

    public function scopeValidLogo($query)
    {
        $query->where('logo_extension', '!=', '');
    }

    public function getWebsiteAttribute($website)
    {
        if (!empty($website) && strpos($website, 'http') === false)
        {
            return 'http://' . $website;
        }

        return $website;
    }

    public function getTwitterAttribute($twitter)
    {
        if (!empty($twitter) && strpos($twitter, 'http') === false)
        {
            return 'http://' . $twitter;
        }

        return $twitter;
    }

    public function getFacebookAttribute($facebook)
    {
        if (!empty($facebook) && strpos($facebook, 'http') === false)
        {
            return 'http://' . $facebook;
        }

        return $facebook;
    }

    public function getLinkedinAttribute($linkedin)
    {
        if (!empty($linkedin) && strpos($linkedin, 'http') === false)
        {
            return 'http://' . $linkedin;
        }

        return $linkedin;
    }

    public function getGraphDataAttribute()
    {   
        $graph_data = [];

        // Set the starting date.
        $start_date = Carbon::now()->modify('first day of next month')->subYear();

        // Get all the data for the last 12 months.
        $data = collect(DB::table('views')->where('agency_id',$this->id)->where('created_at','>',$start_date)->get())->groupBy(function($view) {
            return Carbon::parse($view->created_at)->format('M y');
        });

        // Generate the last 12 months so we can have zeroes.
        $start = new \DateTime('11 months ago');
        
        // So you don't skip February if today is day the 29th, 30th, or 31st
        $start->modify('first day of this month'); 
        $end = new \DateTime();
        $interval = new \DateInterval('P1M');
        $period = new \DatePeriod($start, $interval, $end);
        foreach ($period as $dt) {
            // dd($dt->format('M y'));
            // $month[] = $dt->format('M y');
            if (empty($data[$dt->format('M y')]))
            {
                // No, make it a zero.
                $graph_data[$dt->format('M y')] = 0;
            }
            else
            {
                $graph_data[$dt->format('M y')] = $data[$dt->format('M y')]->count();
            }
        }

        return ['labels'=>array_keys($graph_data),'data'=>array_values($graph_data)];
    }

    public function getPhoneDisplayAttribute()
    {
        if (strlen($this->phone) == 11)
        {
            return '+' . substr($this->phone, 0,1) . ' (' . substr($this->phone, 1, 3) . ') ' . substr($this->phone, 4, 3) . '-' . substr($this->phone, 7, 4);
        }
        elseif (strlen($this->phone) == 10)
        {
            return '(' . substr($this->phone, 0, 3) . ') ' . substr($this->phone, 3, 3) . '-' . substr($this->phone, 6, 4);
        }
        else
        {
            return $this->phone;
        }
    }

    public function getContactPhoneDisplayAttribute()
    {
        if (strlen($this->contact_phone) == 11)
        {
            return '+' . substr($this->contact_phone, 0,1) . ' (' . substr($this->contact_phone, 1, 3) . ') ' . substr($this->contact_phone, 4, 3) . '-' . substr($this->contact_phone, 7, 4);
        }
        elseif (strlen($this->contact_phone) == 10)
        {
            return '(' . substr($this->contact_phone, 0, 3) . ') ' . substr($this->contact_phone, 3, 3) . '-' . substr($this->contact_phone, 6, 4);
        }
        else
        {
            return $this->contact_phone;
        }
    }
}
