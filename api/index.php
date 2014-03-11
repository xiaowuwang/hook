<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

require __DIR__ . '/vendor/autoload.php';

$app = new \Slim\Slim();
require __DIR__ . '/app/bootstrap.php';

// Middlewares
$app->add(new LogMiddleware());
$app->add(new ResponseTypeMiddleware());
$app->add(new AppMiddleware());

// Attach user authentication
$app->add(new AuthMiddleware());

$app->get('/', function() use ($app) {
	$app->content = models\App::all();
});

/**
 * Misc system utilities
 */
$app->group('/system', function() use ($app) {
	/**
	 * GET /system/time
	 */
	$app->get('/time', function() use ($app) {
		$app->content = time();
	});
});

/**
 * Collection routes
 */
$app->group('/collection', function () use ($app) {

	/**
	 * GET /collection/:name
	 */
	$app->get('/:name', function($name) use ($app) {
		$query = models\Collection::filter($app->request->get('q'))
			->from($name)
			->where('app_id', $app->key->app_id);

		// Apply ordering
		if ($s = $app->request->get('s')) {
			foreach($s as $ordering) {
				$query->orderBy(reset($ordering), end($ordering));
			}
		}

		// Apply group
		if ($group = $app->request->get('g')) {
			foreach($group as $field) {
				$query = $query->groupBy($field);
			}
		}

		// limit / offset
		if ($offset = $app->request->get('offset')) {
			$query = $query->skip($offset);
		}
		if ($limit = $app->request->get('limit')) {
			$query = $query->take($limit);
		}

		if ($aggr = $app->request->get('aggr')) {
			// Aggregate 'max'/'min'/'avg'/'sum' methods
			if ($aggr['field']) {
				$app->content = $query->{$aggr['method']}($aggr['field']);
			} else {
				// Aggregate 'count'
				$app->content = $query->{$aggr['method']}();
			}

		} else if ($app->request->get('p')) {
			// Apply pagination
			$app->content = $query->paginate($app->request->get('p'));

		} else if ($app->request->get('f')) {
			// First
			$app->content = $query->first();

		} else {
			$app->content = $query->get();
		}

	});

	/**
	 * POST /collection/:name
	 */
	$app->post('/:name', function($name) use ($app) {
		$model = new models\Collection(array_merge($app->request->post('data'), array(
			'app_id' => $app->key->app_id,
			'table_name' => $name
		)));

		if (!$model->save()) {
			throw new ForbiddenException("Can't save '{$name}'.");
		}

		$app->content = $model;
	});

	/**
	 * PUT /collection/:name
	 */
	$app->put('/:name', function($name) use ($app) {
		$query = models\Collection::filter($app->request->post('q'))
			->from($name)
			->where('app_id', $app->key->app_id);

		if ($operation = $app->request->post('op')) {
			// Operations: increment/decrement
			$app->content = $query->{$operation['method']}($operation['field'], $operation['value']);
		} else {

			// Perform raw update
			//
			// FIXME: 'd' is deprecated. use 'data' instead.
			//
			// Who is using it?
			// - 'plugados-site'
			// - 'clubsocial-possibilidades'
			//
			$app->content = $query->update($app->request->post('d') ?: $app->request->post('data'));
		}
	});

	/**
	 * TODO: DRY with PUT /collection/:name
	 * PUT /collection/:name/:id
	 * Curently only used on : Only Backbone.DLModel
	 */
	$app->put('/:name/:id', function($name, $id) use ($app) {
		$query = models\Collection::from($name)
			->where('app_id', $app->key->app_id)
			->where('_id', $id);

		if ($operation = $app->request->post('op')) {
			// Operations: increment/decrement
			$app->content = $query->{$operation['method']}($operation['field'], $operation['value']);
		} else {
			// Perform raw update
			$app->content = $query->update($app->request->post('data'));
		}
	});

	/**
	 * DELETE /collection/:name
	 */
	$app->delete('/:name', function($name) use ($app) {
		$query = models\Collection::filter($app->request->get('q'))
			->from($name)
			->where('app_id', $app->key->app_id);

		$app->content = array('success' => $query->delete());
	});

	/**
	 * GET /collection/:name/:id
	 */
	$app->get('/:name/:id', function($name, $id) use ($app) {
		$app->content = models\App::collection($name)->find($id);
	});

	/**
	 * POST /collection/:name/:id
	 */
	$app->post('/:name/:id', function($name, $id) use ($app) {
		$app->content = array(
			'success' => models\App::collection($name)->find($id)->update($app->request->post('data'))
		);
	});

	/**
	 * DELETE /collection/:name/:id
	 */
	$app->delete('/:name/:id', function($name, $id) use ($app) {
		$app->content = array('success' => models\App::collection($name)->find($id)->destroy());
	});

	/**
	 * Nested collections
	 */
	// $app->get('/:name/:id', function($name, $id) use ($app) {
	// 	$app->content = array('success' => models\Collection::query()->from($name)->where('_id', $id)->delete());
	// });

});

/**
 * Realtime channels
 */
$app->group('/channels', function() use ($app) {
	/**
	 * GET /channels/channel
	 * GET /channels/some/deep/channel
	 */
	$app->get('/:name+', function($name) use ($app) {
		$name = implode("/",$name);
		$app->content = models\ChannelMessage::filter($app->request->get('q'))
			->where('app_id', $app->key->app_id)
			->where('channel', $name)
			->get();
	});

	/**
	 * POST /channels/channel
	 * POST /channels/some/deep/channel
	 */
	$app->post('/:name+', function($name) use ($app) {
		$name = implode("/",$name);
		$app->content = models\ChannelMessage::create(array_merge($app->request->post('data'), array(
			'app_id' => $app->key->app_id,
			'channel' => $name
		)));
	});
});

/**
 * Authentication API
 */
$app->group('/auth', function() use ($app) {
	/**
	 * POST /auth/facebook
	 * POST /auth/email
	 */
	$app->post('/:provider(/:method)', function($provider_name, $method = 'authenticate') use ($app) {
		$data = $app->request->post();
		$data['app_id'] = $app->key->app_id;
		$app->content = Auth\Provider::get($provider_name)->{$method}($data);
	});
});


/**
 * Key/value routes
 */
$app->group('/key', function() use ($app) {

	/**
	 * GET /key/:name
	 */
	$app->get('/:key', function($key) use ($app) {
		$app->content = models\KeyValue::where('app_id', $app->key->app_id)
			->where('name', $key)
			->first() ?: new models\KeyValue();
	});

	/**
	 * PUT /key/:name
	 */
	$app->post('/:key', function($key) use ($app) {
		$app->content = models\KeyValue::upsert(array(
			'app_id' => $app->key->app_id,
			'name' => $key,
			'value' => $app->request->post('value')
		));
	});

});

/**
 * File API
 */
$app->group('/files', function() use($app) {
	function storagePath($app){
		return '/app/storage/files/' . $app->key->app_id;
	}
	function storageURL($filePath){
		$protocol = "http://";
		$uri = str_replace("index.php", "", $_SERVER["SCRIPT_NAME"]);
		return $protocol . $_SERVER["SERVER_NAME"] . "/". $uri . $filePath;
	}

	/**
	 * GET /files/:id
	 */
	$app->get('/:id', function($id) use ($app){
		$file = models\File::find($id);
		if(!$file){
			$app->response->setStatus(404);
			return;
		}
		$file->url = storageURL($file->path);
		$app->content = $file->toJson();
	});

	/**
	 * POST /files
	 */
	$app->post('/:provider', function($provider = 'filesystem') use ($app) {
		if(!isset($_FILES["file"])){
			throw new \Exception("'file' field not provided");
		}

		$rawFile = $_FILES["file"];
		$fileStoragePath = storagePath($app);
		$storageProvider = Storage\Provider::get($provider);
		$uploadedFile = $storageProvider->upload(__DIR__.$fileStoragePath, $rawFile);

		if($uploadedFile == NULL){
			throw new \Exception("error when uploading file");
		}

		$file = models\File::create(array(
			'app_id' => $app->key->app_id,
			'file' => $rawFile,
			'path' => $fileStoragePath . '/' . $uploadedFile
		));

		$file->url = storageURL($file->path);
		$app->content = $file->toJson();
	});

});


/**
 * Internals
 */
$app->group('/apps', function() use ($app) {
	$app->get('/test', function() use ($app) {
		$app = models\App::create(array(
			'_id' => 1,
			'name' => "test"
		));
		$app->keys()->create(array(
			'key' => 'test',
			'secret' => 'test'
		));
		$app->content = models\App::all();
	});

	/**
	 * GET /apps/list
	 */
	$app->get('/list', function() use($app) {
		$app->content = models\App::all();
	});

	/**
	 * GET /apps/by_name/:name
	 */
	$app->get('/by_name/:name', function($name) use ($app) {
		$app->content = models\App::where('name', $name)->first();
	});

	$app->post('/', function() use ($app) {
		$app->content = models\App::create($app->request->post('app'));
	});

	$app->get('/keys', function() use ($app) {
		$app->content = $app->key->app->toArray();
	});

	$app->post('/keys', function() use ($app) {
		$app->content = $app->key->app->generate_key();
	});

	$app->get('/configs', function() use ($app) {
		$app->content = $app->key->app->configs;
	});

	$app->post('/configs', function() use ($app) {
		$_app = $app->key->app;
		foreach($app->request->post('configs', array()) as $config) {
			$existing = $_app->configs()->where('name', $config['name'])->first();
			if ($existing) {
				$existing->update($config);
			} else {
				$_app->configs()->create($config);
			}
		}
		$app->content = array('success' => true);
	});

	$app->delete('/configs/:config', function($config) use ($app) {
		$app->content = array(
			'success' => ($app->key->app->configs()
				->where('name', $config)
				->first()
				->delete()) == 1
		);
	});

	$app->get('/modules', function() use ($app) {
		$app->content = $app->key->app->modules;
	});

	$app->post('/modules', function() use ($app) {
		$data = $app->request->post('module');
		$data['app_id'] = $app->key->app_id;

		// try to retrieve existing module for this app
		$module = models\Module::where('app_id', $data['app_id'])
			->where('name', $data['name'])
			->first();

		$app->content = ($module) ? $module->update($data) : models\Module::create($data);
	});

	$app->delete('/modules/:name', function($name) use ($app) {
		$deleted = models\Module::where('app_id', $app->key->app_id)->
			where('name', $name)->
			delete();
		$app->content = array('success' => $deleted);
	});

	// $app->get('/:name/composer', function($id) use ($app) {
	// // $app->post('/:id/composer', function($id) use ($app) {
	// 	// $composer = json_decode(file_get_contents('composer.json'));
	// 	// $composer['require'] = array_merge($composer['require'], $app->request->post('require'));
	// 	// file_put_contents('composer.json', json_encode($composer));
	// 	//Create the application and run it with the commands
	// 	error_reporting(E_ALL);
	// 	ini_set('display_errors', 1);
	// 	chdir('../');
	// 	$application = new Composer\Console\Application();
	// 	$application->run(new Symfony\Component\Console\Input\ArgvInput(array('update', 'willdurand/geocoder')));
	// 	var_dump("Finished.");
	// });

	$app->delete('/:id', function($id) {
		echo json_encode(array('success' => $app->key->app->destroy()));
	});
});

$app->run();
