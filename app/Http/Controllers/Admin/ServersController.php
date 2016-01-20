<?php
/**
 * Pterodactyl Panel
 * Copyright (c) 2015 - 2016 Dane Everitt <dane@daneeveritt.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Pterodactyl\Http\Controllers\Admin;

use Alert;
use Debugbar;
use Log;

use Pterodactyl\Models;
use Pterodactyl\Repositories\ServerRepository;

use Pterodactly\Exceptions\DisplayException;
use Pterodactly\Exceptions\DisplayValidationException;

use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServersController extends Controller
{

    /**
     * Controller Constructor
     */
    public function __construct()
    {
        //
    }

    public function getIndex(Request $request)
    {
        return view('admin.servers.index', [
            'servers' => Models\Server::select('servers.*', 'nodes.name as a_nodeName', 'users.email as a_ownerEmail')
                ->join('nodes', 'servers.node', '=', 'nodes.id')
                ->join('users', 'servers.owner', '=', 'users.id')
                ->paginate(20),
        ]);
    }

    public function getNew(Request $request)
    {
        return view('admin.servers.new', [
            'locations' => Models\Location::all(),
            'services' => Models\Service::all()
        ]);
    }

    public function getView(Request $request, $id)
    {
        $server = Models\Server::select(
            'servers.*',
            'nodes.name as a_nodeName',
            'users.email as a_ownerEmail',
            'locations.long as a_locationName',
            'services.name as a_serviceName',
            'services.executable as a_serviceExecutable',
            'service_options.name as a_servceOptionName'
        )->join('nodes', 'servers.node', '=', 'nodes.id')
        ->join('users', 'servers.owner', '=', 'users.id')
        ->join('locations', 'nodes.location', '=', 'locations.id')
        ->join('services', 'servers.service', '=', 'services.id')
        ->join('service_options', 'servers.option', '=', 'service_options.id')
        ->where('servers.id', $id)
        ->first();

        if (!$server) {
            return abort(404);
        }

        return view('admin.servers.view', [
            'server' => $server,
            'assigned' => Models\Allocation::select('id', 'ip', 'port')->where('assigned_to', $id)->orderBy('ip', 'asc')->orderBy('port', 'asc')->get(),
            'unassigned' => Models\Allocation::select('id', 'ip', 'port')->where('node', $server->node)->whereNull('assigned_to')->orderBy('ip', 'asc')->orderBy('port', 'asc')->get(),
            'startup' => Models\ServiceVariables::select('service_variables.*', 'server_variables.variable_value as a_serverValue')
                ->join('server_variables', 'server_variables.variable_id', '=', 'service_variables.id')
                ->where('service_variables.option_id', $server->option)
                ->where('server_variables.server_id', $server->id)
                ->get()
        ]);
    }

    public function postNewServer(Request $request)
    {

        try {

            $server = new ServerRepository;
            $response = $server->create($request->all());

            return redirect()->route('admin.servers.view', [ 'id' => $response ]);

        } catch (\Exception $e) {

            if ($e instanceof \Pterodactyl\Exceptions\DisplayValidationException) {
                return redirect()->route('admin.servers.new')->withErrors(json_decode($e->getMessage()))->withInput();
            } else if ($e instanceof \Pterodactyl\Exceptions\DisplayException) {
                Alert::danger($e->getMessage())->flash();
            } else {
                Debugbar::addException($e);
                Alert::danger('An unhandled exception occured while attemping to add this server. Please try again.')->flash();
            }

            return redirect()->route('admin.servers.new')->withInput();

        }

    }

    /**
     * Returns a JSON tree of all avaliable nodes in a given location.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function postNewServerGetNodes(Request $request)
    {

        if(!$request->input('location')) {
            return response()->json([
                'error' => 'Missing location in request.'
            ], 500);
        }

        return response()->json(Models\Node::select('id', 'name', 'public')->where('location', $request->input('location'))->get());

    }

    /**
     * Returns a JSON tree of all avaliable IPs and Ports on a given node.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function postNewServerGetIps(Request $request)
    {

        if(!$request->input('node')) {
            return response()->json([
                'error' => 'Missing node in request.'
            ], 500);
        }

        $ips = Models\Allocation::where('node', $request->input('node'))->whereNull('assigned_to')->get();
        $listing = [];

        foreach($ips as &$ip) {
            if (array_key_exists($ip->ip, $listing)) {
                $listing[$ip->ip] = array_merge($listing[$ip->ip], [$ip->port]);
            } else {
                $listing[$ip->ip] = [$ip->port];
            }
        }
        return response()->json($listing);

    }

    /**
     * Returns a JSON tree of all avaliable options for a given service.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function postNewServerServiceOptions(Request $request)
    {

        if(!$request->input('service')) {
            return response()->json([
                'error' => 'Missing service in request.'
            ], 500);
        }

        $service = Models\Service::select('executable', 'startup')->where('id', $request->input('service'))->first();
        return response()->json([
            'exec' => $service->executable,
            'startup' => $service->startup,
            'options' => Models\ServiceOptions::select('id', 'name', 'docker_image')->where('parent_service', $request->input('service'))->orderBy('name', 'asc')->get()
        ]);

    }

    /**
     * Returns a JSON tree of all avaliable variables for a given service option.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function postNewServerServiceVariables(Request $request)
    {

        if(!$request->input('option')) {
            return response()->json([
                'error' => 'Missing option in request.'
            ], 500);
        }

        return response()->json(Models\ServiceVariables::where('option_id', $request->input('option'))->get());

    }

    public function postUpdateServerDetails(Request $request, $id)
    {

        try {

            $server = new ServerRepository;
            $server->updateDetails($id, [
                'owner' => $request->input('owner'),
                'name' => $request->input('name'),
                'reset_token' => ($request->input('reset_token', false) === 'on') ? true : false
            ]);

            Alert::success('Server details were successfully updated.')->flash();
            return redirect()->route('admin.servers.view', [
                'id' => $id,
                'tab' => 'tab_details'
            ]);

        } catch (\Exception $e) {

            if ($e instanceof \Pterodactyl\Exceptions\DisplayValidationException) {
                return redirect()->route('admin.servers.view', [
                    'id' => $id,
                    'tab' => 'tab_details'
                ])->withErrors(json_decode($e->getMessage()))->withInput();
            } else if ($e instanceof \Pterodactyl\Exceptions\DisplayException) {
                Alert::danger($e->getMessage())->flash();
            } else {
                Log::error($e);
                Alert::danger('An unhandled exception occured while attemping to add this server. Please try again.')->flash();
            }

            return redirect()->route('admin.servers.view', [
                'id' => $id,
                'tab' => 'tab_details'
            ])->withInput();

        }
    }

    public function postUpdateServerToggleBuild(Request $request, $id) {
        $server = Models\Server::findOrFail($id);
        $node = Models\Node::findOrFail($server->node);
        $client = Models\Node::guzzleRequest($server->node);

        try {
            $res = $client->request('POST', '/server/rebuild', [
                'headers' => [
                    'X-Access-Server' => $server->uuid,
                    'X-Access-Token' => $node->daemonSecret
                ]
            ]);
            Alert::success('A rebuild has been queued successfully. It will run the next time this server is booted.')->flash();
        } catch (\GuzzleHttp\Exception\TransferException $ex) {
            Log::warning($ex);
            Alert::danger('An error occured while attempting to toggle a rebuild: ' . $ex->getMessage())->flash();
        }

        return redirect()->route('admin.servers.view', [
            'id' => $id,
            'tab' => 'tab_manage'
        ]);
    }

    public function postUpdateServerUpdateBuild(Request $request, $id)
    {
        try {

            $server = new ServerRepository;
            $server->changeBuild($id, [
                'default' => $request->input('default'),
                'add_additional' => $request->input('add_additional'),
                'remove_additional' => $request->input('remove_additional'),
                'memory' => $request->input('memory'),
                'swap' => $request->input('swap'),
                'io' => $request->input('io'),
                'cpu' => $request->input('cpu'),
            ]);
            Alert::success('Server details were successfully updated.')->flash();
        } catch (\Exception $e) {

            if ($e instanceof \Pterodactyl\Exceptions\DisplayValidationException) {
                return redirect()->route('admin.servers.view', [
                    'id' => $id,
                    'tab' => 'tab_build'
                ])->withErrors(json_decode($e->getMessage()))->withInput();
            } else if ($e instanceof \Pterodactyl\Exceptions\DisplayException) {
                Alert::danger($e->getMessage())->flash();
            } else {
                Log::error($e);
                Alert::danger('An unhandled exception occured while attemping to add this server. Please try again.')->flash();
            }
        }
        return redirect()->route('admin.servers.view', [
            'id' => $id,
            'tab' => 'tab_build'
        ]);
    }

    public function deleteServer(Request $request, $id, $force = null)
    {
        try {
            $server = new ServerRepository;
            $server->deleteServer($id, $force);
            Alert::success('Server was successfully deleted from the panel and the daemon.')->flash();
            return redirect()->route('admin.servers');
        } catch (\Pterodactyl\Exceptions\DisplayException $e) {
            Alert::danger($e->getMessage())->flash();
        } catch(\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attemping to add this server. Please try again.')->flash();
        }
        return redirect()->route('admin.servers.view', [
            'id' => $id,
            'tab' => 'tab_delete'
        ]);
    }

    public function postToggleInstall(Request $request, $id)
    {
        try {
            $server = new ServerRepository;
            $server->toggleInstall($id);
            Alert::success('Server status was successfully toggled.')->flash();
        } catch(\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attemping to toggle this servers status.')->flash();
        } finally {
            return redirect()->route('admin.servers.view', [
                'id' => $id,
                'tab' => 'tab_manage'
            ]);
        }
    }

    public function postUpdateServerStartup(Request $request, $id)
    {
        try {
            $server = new ServerRepository;
            $server->updateStartup($id, $request->except([
                '_token'
            ]));
            Alert::success('Server startup variables were successfully updated.')->flash();
        } catch (\Pterodactyl\Exceptions\DisplayException $e) {
            Alert::danger($e->getMessage())->flash();
        } catch(\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attemping to update startup variables for this server. Please try again.')->flash();
        } finally {
            return redirect()->route('admin.servers.view', [
                'id' => $id,
                'tab' => 'tab_startup'
            ])->withInput();
        }
    }

}
