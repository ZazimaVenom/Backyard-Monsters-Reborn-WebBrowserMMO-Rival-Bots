<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function respond(array $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit; }
function positiveInt(array $input,string $key): int { $value=$input[$key]??null;if(!is_int($value)&&!is_string($value)){throw new RuntimeException('Invalid '.$key.'.');}$result=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($result===false){throw new RuntimeException('Invalid '.$key.'.');}return $result; }
function scalarString(array $input,string $key,string $default=''): string { $v=$input[$key]??$default;if(!is_scalar($v)){throw new RuntimeException('Invalid '.$key.'.');}return (string)$v; }
try{
    if(!Game::configured()){respond(['ok'=>false,'error'=>'Install the world from setup.php on the local computer.','code'=>'not_installed'],503);}
    World::assertSchema();
    $action=scalarString($_GET,'action','bootstrap');$method=$_SERVER['REQUEST_METHOD']??'GET';
    $read=['realmChat','fieldGuide','eventCalendar','eventLeaders','bootstrap','opponents','rankings','visit','reports','battle','worldMap','worldRegions','worldSite','alliances','tribes','tribeVisit','neighbors','neighborThread','truces','inferno','infernoVisit','infernoRivals','infernoRivalVisit','ascension'];$writes=['realmChatAct','eventRound','register','login','logout','worldPulse','worldLeave','baiterStart','baiterPoll','baiterSiren','build','move','upgrade','repair','repairBase','fortify','cancelFortify','yardPurchase','hireWorker','plannerSave','plannerDelete','plannerApply','collect','train','research','unlock','juice','garrison','ungarrison','cancelTrain','championRaise','championFeed','championFreeze','championThaw','labResearch','labCancel','catapult','attack','deploy','deployChampion','retreat','outpostAct','outpostClaim','outpostLaunch','worldAttack','worldRelocate','worldBookmark','worldUpgrade','worldRepair','worldGarrison','worldUngarrison','worldAbandon','tribeAttack','tribeCollect','hellRaisersBuy','allianceManage','questClaim','neighborAction','truceAction','siegeFire','siegeResearch','siegeBuild','siegeCancel','infernoAct','infernoAttack','infernoRivalAttack','infernoDefense','ascendMonsters','blitzStart','brukkargDefend','horseStart','wmiStart','molochStart','emergenceStart','emergenceRaise','seasonalAction','mushroomPick','signChange','radioTune','radioRead','decorationBuy','decorationMove','decorationPlace','decorationStore'];
    if(!in_array($action,array_merge($read,$writes),true)){respond(['ok'=>false,'error'=>'Unknown action.','code'=>'not_found'],404);}
    if(in_array($action,$read,true)&&$method!=='GET'||in_array($action,$writes,true)&&$method!=='POST'){header('Allow: '.(in_array($action,$read,true)?'GET':'POST'));respond(['ok'=>false,'error'=>'Method not allowed.','code'=>'method_not_allowed'],405);}
    $input=[];
    if($method==='POST'){
        $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!is_string($token)||!hash_equals($_SESSION['csrf']??'',$token)){respond(['ok'=>false,'error'=>'Your session changed. Refresh the page and try again.','code'=>'csrf'],403);}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>65536){respond(['ok'=>false,'error'=>'Request too large.','code'=>'too_large'],413);}
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')){respond(['ok'=>false,'error'=>'Send JSON data.','code'=>'content_type'],415);}
        $body=file_get_contents('php://input',false,null,0,65537);if(strlen($body)>65536){respond(['ok'=>false,'error'=>'Request too large.','code'=>'too_large'],413);}
        try{$input=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){respond(['ok'=>false,'error'=>'Invalid JSON.','code'=>'invalid_json'],400);}
        if(!is_array($input)){respond(['ok'=>false,'error'=>'Expected a JSON object.','code'=>'invalid_json'],400);}
    }
    if($action==='bootstrap'){respond(['ok'=>true,'data'=>Game::snapshot(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null)]);}
    if($action==='register'||$action==='login'){
        $previousId=(int)($_SESSION['user_id']??0);$previousSession=session_id();
        $name=scalarString($input,'name');$password=scalarString($input,'password');
        $user=$action==='register'?Game::register($name,$password,is_string($input['invite']??null)?$input['invite']:''):Game::login($name,$password);
        if($previousId>0){WorldActivity::logout($previousId,$previousSession);}
        respond(['ok'=>true,'data'=>['user'=>$user,'csrf'=>$_SESSION['csrf']]]);
    }
    if(!isset($_SESSION['user_id'])){respond(['ok'=>false,'error'=>'Please sign in to continue.','code'=>'unauthorized'],401);}$id=Game::requireUser();
    if($action==='logout'){WorldActivity::logout($id,session_id());$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));respond(['ok'=>true,'data'=>['csrf'=>$_SESSION['csrf']]]);}
    if($action==='worldPulse'||$action==='worldLeave'){
        $clientId=$input['clientId']??null;if(!is_string($clientId)){throw new RuntimeException('This game tab has an invalid presence identifier. Refresh the page.');}
        if($action==='worldLeave'){WorldActivity::leave($id,session_id(),$clientId);}
        else{WorldActivity::pulse($id,session_id(),$clientId);}
        // Autonomous work must not hold the player's PHP session lock. Another
        // tab can keep playing or sign out while this small batch completes.
        session_write_close();
        if($action==='worldPulse'){
            try{Bots::tick(null,1.0);}
            catch(Throwable $error){
                try{WorldActivity::recordTick(['status'=>'error','errors'=>[['message'=>'World activity failed.']]]);}catch(Throwable){}
                throw $error;
            }
        }
        respond(['ok'=>true,'data'=>['world'=>Game::worldSummary(),'serverTime'=>time()]]);
    }
    if($action==='opponents'||$action==='rankings'){$data=Game::listing($action,$id,isset($_GET['page'])?positiveInt($_GET,'page'):1,scalarString($_GET,'search'),scalarString($_GET,'filter','all'));}
    elseif($action==='signChange'){$data=YardMessages::changeSign($id,$input);}
    elseif($action==='inferno'){$data=InfernoRealm::view($id);}
    elseif($action==='ascension'){$data=InfernoAscension::view($id);}
    elseif($action==='ascendMonsters'){$data=InfernoAscension::transfer($id,$input);}
    elseif($action==='infernoRivals'){$data=InfernoRivals::opponents($id,isset($_GET['page'])?positiveInt($_GET,'page'):1,scalarString($_GET,'search'),scalarString($_GET,'filter','all'));}
    elseif($action==='infernoRivalVisit'){$data=InfernoRivals::visit($id,positiveInt($_GET,'keeper'));}
    elseif($action==='infernoRivalAttack'){$data=BattleService::startInfernoRival($id,positiveInt($input,'keeper'),$input);}
    elseif($action==='infernoVisit'){$data=InfernoRealm::visit($id,positiveInt($_GET,'stage'));}
    elseif($action==='infernoAct'){$data=InfernoRealm::act($id,$input);}
    elseif($action==='infernoDefense'){$data=InfernoDefense::run($id,$input);}
    elseif($action==='infernoAttack'){$data=BattleService::startInferno($id,positiveInt($input,'stage'),$input);}
    elseif($action==='truces'){$data=Truces::view($id,isset($_GET['keeper'])?positiveInt($_GET,'keeper'):0);}
    elseif($action==='truceAction'){$data=Truces::act($id,$input);}
    elseif($action==='siegeFire'){
        if(!isset($input['x'],$input['y'])||!is_numeric($input['x'])||!is_numeric($input['y'])){throw new RuntimeException('Choose a valid siege target.');}
        $data=BattleService::siege($id,positiveInt($input,'battleId'),scalarString($input,'type'),(float)$input['x'],(float)$input['y'],$input['requestId']??null);
    }
    elseif($action==='alliances'){$data=Alliances::browse($id,$_GET);}
    elseif($action==='allianceManage'){$data=Alliances::manage($id,$input);}
    elseif($action==='realmChat'){$data=RealmChat::view($id,scalarString($_GET,'room'));}
    elseif($action==='realmChatAct'){$data=RealmChat::act($id,$input);}
    elseif($action==='fieldGuide'){$data=GameLanguage::guide();}
    elseif($action==='eventCalendar'){$data=EventCalendar::summary(Game::loadBase($id),time());}
    elseif($action==='eventLeaders'){$data=EventCalendar::leaders($id,scalarString($_GET,'key'),positiveInt($_GET,'start'));}
    elseif($action==='eventRound'){$data=['calendar'=>EventCalendar::act($id,$input),'state'=>Game::snapshot($id)];}
    elseif($action==='tribes'){$data=Tribes::listing($id);}
    elseif($action==='tribeVisit'){$data=Tribes::visit($id,positiveInt($_GET,'stage'));}
    elseif($action==='tribeAttack'){$data=BattleService::startTribe($id,positiveInt($input,'stage'),$input);}
    elseif($action==='hellRaisersBuy'){$data=HellRaisers::purchase($id,scalarString($input,'prize'),positiveInt($input,'run'));}
    elseif($action==='tribeCollect'){$data=Tribes::collect($id);}
    elseif($action==='neighbors'){$data=Neighbors::overview($id,scalarString($_GET,'search'));}
    elseif($action==='neighborThread'){$data=Neighbors::thread($id,positiveInt($_GET,'keeper'),isset($_GET['before'])?positiveInt($_GET,'before'):null);}
    elseif($action==='neighborAction'){$data=Neighbors::act($id,$input);}
    elseif($action==='visit'){$data=Game::visit(positiveInt($_GET,'id'),$id);}
    elseif($action==='worldRegions'){$data=Regions::overview($id);}
    elseif($action==='worldBookmark'){$data=Regions::bookmark($id,$input);}
    elseif($action==='worldRelocate'){$data=Regions::act($id,$input);}
    elseif($action==='worldMap'){$data=World::map($id,$_GET);}
    elseif($action==='outpostLaunch'){$data=OutpostYards::launch($id,positiveInt($input,'siteId'),positiveInt($input,'targetId'));}
    elseif($action==='outpostAct'){$data=OutpostYards::edit($id,$input);}
    elseif($action==='outpostClaim'){$data=OutpostYards::claim($id,$input);}
    elseif($action==='worldSite'){$data=World::site($id,positiveInt($_GET,'id'));}
    elseif($action==='worldAttack'){$data=BattleService::startSite($id,positiveInt($input,'siteId'));}
    elseif(in_array($action,['worldUpgrade','worldRepair','worldGarrison','worldUngarrison','worldAbandon'],true)){$data=World::mutate($id,$action,$input);}
    elseif(in_array($action,['baiterStart','baiterPoll','baiterSiren','blitzStart','brukkargDefend','horseStart','wmiStart','molochStart','emergenceStart'],true)){$data=BaiterService::run($id,$action,$input);}
    elseif($action==='reports'){$data=Game::reports($id);}
    elseif($action==='battle'){$data=BattleService::poll($id,positiveInt($_GET,'id'));}
    elseif($action==='attack'){$data=BattleService::start($id,positiveInt($input,'targetId'));}
    elseif($action==='deploy'){
        if(!isset($input['x'],$input['y'])||!is_numeric($input['x'])||!is_numeric($input['y'])){throw new RuntimeException('Choose a valid deployment point.');}
        $troops=scalarString($input,'type');
        if(array_key_exists('troops',$input)){if(!is_array($input['troops'])){throw new RuntimeException('Choose a monster bucket.');}$troops=$input['troops'];}
        $data=BattleService::deploy($id,positiveInt($input,'battleId'),$troops,(float)$input['x'],(float)$input['y']);
    }
    elseif($action==='deployChampion'){
        if(!isset($input['x'],$input['y'])||!is_numeric($input['x'])||!is_numeric($input['y'])){throw new RuntimeException('Choose a valid deployment point.');}
        $data=BattleService::deployChampion($id,positiveInt($input,'battleId'),(float)$input['x'],(float)$input['y'],($input['type']??null)==='krallen');
    }
    elseif($action==='catapult'){
        if(!isset($input['x'],$input['y'])||!is_numeric($input['x'])||!is_numeric($input['y'])){throw new RuntimeException('Choose a valid Catapult target.');}
        $data=BattleService::catapult($id,positiveInt($input,'battleId'),scalarString($input,'type'),positiveInt($input,'level'),(float)$input['x'],(float)$input['y']);
    }
    elseif($action==='retreat'){$data=BattleService::retreat($id,positiveInt($input,'battleId'));}
    else{$data=Game::mutate($id,$action,$input);}
    respond(['ok'=>true,'data'=>$data]);
}catch(WorldUpgradeRequiredException $e){respond(['ok'=>false,'error'=>$e->getMessage(),'code'=>'upgrade_required'],503);}
catch(PDOException $e){error_log('Database error: '.$e->getMessage());respond(['ok'=>false,'error'=>'The database is unavailable or busy. Please try again.','code'=>'database_error'],503);}
catch(RuntimeException|InvalidArgumentException $e){respond(['ok'=>false,'error'=>$e->getMessage(),'code'=>'invalid_action'],400);}
catch(Throwable $e){error_log((string)$e);respond(['ok'=>false,'error'=>'Something went wrong. Check the local PHP error log.','code'=>'internal_error'],500);}
