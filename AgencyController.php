<?php

namespace App\Http\Controllers\Admin;

use App\Location;
use App\Stage;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use App\Agency;
use App\County;
use App\Entrepreneur;
use App\User;

use Event;
use App\Events\StatusWasApproved;

class AgencyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $agencies = Agency::orderBy('name')->get();

        return view('admin/agency/index', compact('agencies'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $counties = County::where('active', 1)->orderBy('name','ASC')->get();
        $users = User::all();

        return view('admin/agency/create', compact('counties', 'users'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        $input = $request->all();

        $location_json = json_decode(file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($input['address'])));

        $location = new Location;
        $location->address = $input['address'];

        if (!empty($location_json->results[0])) {
            // Add the lat and lng to the location array.
            $location->latitude = $location_json->results[0]->geometry->location->lat;
            $location->longitude = $location_json->results[0]->geometry->location->lng;
        } else {
            $location->latitude = "";
            $location->longitude = "";
        }

        $location->save();

        // unset the address, we dont wanna try to enter a value
        // of a column that we dont have anymore!
        unset($input['address']);

        // $input['user_id'] = Auth::user()->id;

        $input['slug'] = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['name']));

        // Make sure that this slug is unique.
        $slugs = Agency::lists('slug')->toArray();

        if (in_array($input['slug'], $slugs)) {
            $fix_slug = true;
        } else {
            $fix_slug = false;
        }

        $input['approved'] = 1;

        $agency = Agency::create($input);

        if ($fix_slug) {
            $agency->slug = $agency->slug . '-' . $agency->id;
            $agency->save();
        }

        if (!empty($input['counties'])) {
            // Now that we have the agency, add any counties that they had selected.
            foreach ($input['counties'] as $county_id) {
                $agency->counties()->attach($county_id);
            }
        }

        $agency->locations()->attach($location->id);
        return redirect('admin/agency');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show(Agency $agency)
    {
        $users = User::all();

        $counties = County::where('active', 1)->orderBy('name','ASC')->get();

        $entrepreneurs = Entrepreneur::where('approved', 1)->get();

        return view('admin/agency/show', compact('agency', 'counties', 'entrepreneurs', 'users'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Agency $agency)
    {
        $users = User::all();
        $stages = Stage::all();

        $counties = County::where('active', 1)->orderBy('name','ASC')->get();

        $entrepreneurs = Entrepreneur::where('approved', 1)->get();

        return view('admin/agency/edit', compact('agency', 'stages', 'counties', 'entrepreneurs', 'users'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Agency $agency)
    {
        // If an image was uploaded with this county we want to save it.
        if ($request->hasFile('logo')) {
            $request->file('logo')->move(public_path('agencies/logo'), $agency->id . '.' . $request->file('logo')->getClientOriginalExtension());

            $agency->logo_extension = $request->file('logo')->getClientOriginalExtension();
        }

        if ($request->hasFile('background')) {
            $request->file('background')->move(public_path('agencies'), $agency->id . '.' . $request->file('background')->getClientOriginalExtension());

            $agency->background_extension = $request->file('background')->getClientOriginalExtension();
        }

        $stages = $request->input("stages");

        if ($stages == null) {
            $stages = [];
        }

        $agency->additionalStages()->sync($stages);

        $input = $request->all();

        if (isset($input['address']) && $input['address'] != "") {
            $location = Location::where('address', $input['address'])->first();

            if (is_null($location)) {
                $location = new Location;
                $location->address = $input['address'];

                $location_json = json_decode(file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($input['address'])));

                if (!empty($location_json->results[0])) {
                    // Add the lat and lng to the location array.
                    $location->latitude = $location_json->results[0]->geometry->location->lat;
                    $location->longitude = $location_json->results[0]->geometry->location->lng;
                }

                $location->save();
            }

            if (!$agency->locations->contains($location->id)) {
                $agency->locations()->attach($location->id);
            }
        }

        unset($input['address']);

        $agency->fill($input);
        $agency->save();

        return redirect('admin/agency/' . $agency->slug);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Agency $agency)
    {
        //
    }

    public function status(Agency $agency)
    {
        if ($agency->approved) {
            $agency->approved = 0;
        } else {
            $agency->approved = 1;

            // Agency approved email.
            if($agency->contact_email && $agency->name)
            {
                Event::fire(new StatusWasApproved([
                    'subject' => 'Your agency was approved!',
                    'template' => 'emails.agency.approved',
                    'to' => $agency->contact_email,
                    'to_name' => $agency->name,
                    'from' => 'webmaster@flvec.com',
                    'reply_to' => 'webmaster@flvec.com',
                ]));
            }
        }

        $agency->save();

        return redirect()->back();
    }

    public function featured(Agency $agency)
    {
        if ($agency->featured) {
            $agency->featured = 0;
        } else {
            $agency->featured = 1;
        }

        $agency->save();

        return redirect()->back();
    }

    public function top_billing(Agency $agency)
    {
        if ($agency->top_billing) {
            $agency->top_billing = 0;
        } else {
            $agency->top_billing = 1;
        }

        $agency->save();

        return redirect()->back();
    }

    public function premium(Agency $agency)
    {
        if ($agency->premium) {
            $agency->premium = 0;
        } else {
            $agency->premium = 1;
        }

        $agency->save();

        return redirect()->back();
    }

    public function deleteImage(Agency $agency, $image)
    {
        if ($image == 'logo') {
            $agency->logo_extension = '';
        }

        if ($image == 'background') {
            $agency->background_extension = '';
        }

        $agency->save();

        return redirect()->back();
    }
}
