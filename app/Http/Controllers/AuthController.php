<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Services\Auth\LoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{

    /**
     * Login service instance.
     *
     * @var \App\Services\Auth\LoginService
     */
    private LoginService $login;

    /**
     * Form validator instance.
     *
     * @var \App\Form\AdminCustomValidator
     */
    private AdminCustomValidator $form;

    /**
     * Create a new controller instance.
     *
     * @param LoginService $login
     * @param AdminCustomValidator $form
     */
    public function __construct
    (
        LoginService $login,
        AdminCustomValidator $form
    )
    {
        $this->login = $login;
        $this->form = $form;
    }

    /**
     * Handle login request.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function login(Request $request)
    {
        if ($request->isMethod('post')) {
            // Validate inputs
            $this->form->validate($request, 'LoginValidate');

            // Attempt login( admin5@example.com, Password123@)
            $response = $this->login->loginAccount($request);
            // dd($response);

            if ($response === true) {
                return redirect()->route("post.index");
                // if (Gate::allows('admin')) {
                //     return redirect()->route('admin.index')->with('success', 'Welcome, Admin!');
                // }

                // return redirect()->route('user.index')->with('success', 'Welcome, User!');
            }

            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['login' => $response]);
        }

        return view('auth.login');
    }

    /**
     * Handle logout request.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->flush();
        return redirect()->route("login");
    }
}
