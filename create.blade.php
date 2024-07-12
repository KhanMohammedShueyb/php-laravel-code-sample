@extends('master')

@section('head')
    <title>Create Agency</title>
    <meta name="description"
          content="Create your agency.">
@stop

@section('content')
    <div class="headline-bg contact-headline-bg black-bg-color">
    </div><!--//headline-bg-->

    <div class="headline-bg contact-headline-bg agency-bg" style="background: #253340 url('../../images/backgrounds/florida-jacksonville.jpg') no-repeat 50% !important;">
    </div><!--//headline-bg-->

    <!-- ******Contact Section****** -->
    <section class="contact-section section section-on-bg agency-on-bg" style='margin-top:125px;'>
        <div class="container">
            <h2 class="title text-center">Agency Application</h2>

            <p class="intro text-center">Does your organization provide assistance to entrepreneurs in Florida?
                <br>If yes, please fill out the form below to apply to be listed in our directory.</p>

            <form id="contact-form" class="contact-form" method="post" action="{{ route('agency.store') }}">
                <div class="row text-center">
                    <div class="contact-form-inner col-md-8 col-sm-12 col-xs-12 col-md-offset-2 col-sm-offset-0 xs-offset-0">
                        <div class="row">

                            @if (session('success'))
                            <div class="alert alert-success">
                                <p><b>Success!</b> {{ session('success') }}</p>
                            </div>
                            @endif

                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cname">Agency name</label>
                                <input type="text" class="form-control" id="cname" name="name" placeholder="Agency name"
                                       minlength="2" required>
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="caddress">Agency address</label>
                                <input type="text" class="form-control" id="caddress" name="address"
                                       placeholder="Agency address" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency phone</label>
                                <input type="text" class="form-control" id="cphone" name="phone"
                                       placeholder="Agency phone" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cemail">Agency email address</label>
                                <input type="email" class="form-control" id="cemail" name="email"
                                       placeholder="Agency email address" >
                            </div>
                            
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency website</label>
                                <input type="text" class="form-control" id="cwebsite" name="website"
                                       placeholder="Agency website" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency Facebook</label>
                                <input type="text" class="form-control" id="cfacebook" name="facebook"
                                       placeholder="Agency facebook" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency Twitter</label>
                                <input type="text" class="form-control" id="ctwitter" name="twitter"
                                       placeholder="Agency Twitter" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency LinkedIn</label>
                                <input type="text" class="form-control" id="clinkedin" name="linkedin"
                                       placeholder="Agency LinkedIn" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency Pinterest</label>
                                <input type="text" class="form-control" id="cpinerest" name="pinerest"
                                       placeholder="Agency Pinerest" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Agency Instagram</label>
                                <input type="text" class="form-control" id="cinstagram" name="instagram"
                                       placeholder="Agency Instagram" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cname">Contact name</label>
                                <input type="text" class="form-control" id="cname" name="contact_name"
                                       placeholder="Contact name" minlength="2" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cphone">Contact phone</label>
                                <input type="text" class="form-control" id="cphone" name="contact_phone"
                                       placeholder="Contact phone" >
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cemail">Contact email address</label>
                                <input type="email" class="form-control" id="cemail" name="contact_email"
                                       placeholder="Contact email address" >
                            </div>
                            <p style='text-align:left;'>Be very specific.  For example, tell us about your services, workshops, events, grants, incentives, funding options, training, etc.</p>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <label class="sr-only" for="cwhy_contact">Why should an entrepreneur contact your
                                    agency?</label>
                                <textarea class="form-control" id="cwhy_contact" name="why_contact"
                                          placeholder="Why should an entrepreneur contact your agency?" rows="12"
                                          ></textarea>
                            </div>
                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <p>Which counties does your agency serve? (Select all that apply)</p>

                                <div class='row'>
                                    @foreach ($counties as $county)
                                        <div class='col-md-4 col-sm-4 col-xs-12'>
                                            <div class="checkbox">
                                                <label>
                                                    <input type="checkbox" name='counties[]'
                                                           value='{{ $county->id }}'> {{ $county->name }}
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <input type="hidden" name="_token" id="csrf-token" value="{{ Session::token() }}"/>

                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                <button type="submit" class="btn btn-block btn-cta btn-cta-primary">Submit Application
                                </button>
                            </div>
                        </div>
                        <!--//row-->
                    </div>
                </div>
                <!--//row-->
                <div id="form-messages"></div>
            </form>
            <!--//contact-form-->
        </div>
        <!--//container-->
    </section><!--//contact-section-->

@stop