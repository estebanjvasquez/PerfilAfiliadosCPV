<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\JoinViewsModel;

class JoinedViewsController extends Controller
{
    function index()
    {
        $data = JoinViewsModel::join('catalogoView', 'catalogoView.id', '=', 'capacityView.id')
            //->join('city', 'city.state_id', '=', 'state.state_id')
            ->get(['capacityView.name', 'catalogoView.name']);

        return view('admin.join-views', compact('data'));
    }
}
