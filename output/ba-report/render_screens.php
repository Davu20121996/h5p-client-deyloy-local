<?php
require '/var/www/app/vendor/autoload.php';
$app=require '/var/www/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver'=>'array']);
$pages=[
 ['login',null,'/', 'dashboard',[]],
 ['admin-tenants',1,'/admin/tenants','admin',['tenants']],
 ['manager-users',14,'/admin/users','admin',['users']],
 ['manager-tools',14,'/admin/tools','admin',['tools']],
 ['teacher-courses',15,'/','dashboard',[]],
 ['teacher-activities',15,'/courses/12/activities','course',[12,'activities']],
 ['teacher-enrollment',15,'/courses/12/enrollment','course',[12,'enrollment']],
 ['teacher-grades',15,'/courses/12/grades','course',[12,'grades']],
 ['student-activities',16,'/courses/12/activities','course',[12,'activities']],
 ['student-learning',16,'/courses/12/activities/9/learn','learn',[12,9]],
];
$out=[];
foreach($pages as [$key,$user,$path,$method,$args]) {
    Illuminate\Support\Facades\Auth::forgetGuards();
    $request=Illuminate\Http\Request::create('http://localhost'.$path);
    $request->setLaravelSession($app['session']->driver());
    $app->instance('request',$request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    $app['url']->setRequest($request);
    if($user) Illuminate\Support\Facades\Auth::onceUsingId($user);
    $app['view']->share('errors',new Illuminate\Support\ViewErrorBag());
    $controller=$method==='learn' ? new App\Http\Controllers\LtiController() : new App\Http\Controllers\LmsController();
    $response=$controller->$method(...$args);
    $out[$key]=$response instanceof Illuminate\Contracts\View\View ? $response->render() : $response->getContent();
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
