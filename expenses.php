<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time_tracker/credits.htm
// +----------------------------------------------------------------------+

require_once('initialize.php');
import('form.Form');
import('ttUserHelper');
import('ttGroupHelper');
import('DateAndTime');
import('ttTimeHelper');
import('ttExpenseHelper');

// Access checks.
if (!(ttAccessAllowed('track_own_expenses') || ttAccessAllowed('track_expenses'))) {
  header('Location: access_denied.php');
  exit();
}
if (!$user->isPluginEnabled('ex')) {
  header('Location: feature_disabled.php');
  exit();
}
if (!$user->exists()) {
  header('Location: access_denied.php'); // Nobody to enter expenses for.
  exit();
}
if ($user->behalf_id && (!$user->can('track_expenses') || !$user->checkBehalfId())) {
  header('Location: access_denied.php'); // Trying on behalf, but no right or wrong user.
  exit();
}
if (!$user->behalf_id && !$user->can('track_own_expenses') && !$user->adjustBehalfId()) {
  header('Location: access_denied.php'); // Trying as self, but no right for self, and noone to work on behalf.
  exit();
}
if ($request->isPost() && $request->getParameter('user')) {
  if (!$user->isUserValid($request->getParameter('user'))) {
    header('Location: access_denied.php'); // Wrong user id on post.
    exit();
  }
}
// End of access checks.

// Determine user for which we display this page.
$userChanged = $request->getParameter('user_changed');
if ($request->isPost() && $userChanged) {
  $user_id = $request->getParameter('user');
  $user->setOnBehalfUser($user_id);
} else {
  $user_id = $user->getUser();
}

// Initialize and store date in session.
$cl_date = $request->getParameter('date', @$_SESSION['date']);
$selected_date = new DateAndTime(DB_DATEFORMAT, $cl_date);
if($selected_date->isError())
  $selected_date = new DateAndTime(DB_DATEFORMAT);
if(!$cl_date)
  $cl_date = $selected_date->toString(DB_DATEFORMAT);
$_SESSION['date'] = $cl_date;

$tracking_mode = $user->getTrackingMode();
$show_project = MODE_PROJECTS == $tracking_mode || MODE_PROJECTS_AND_TASKS == $tracking_mode;

// Initialize variables.
$cl_client = $request->getParameter('client', ($request->isPost() ? null : @$_SESSION['client']));
$_SESSION['client'] = $cl_client;
$cl_project = $request->getParameter('project', ($request->isPost() ? null : @$_SESSION['project']));
$_SESSION['project'] = $cl_project;
$cl_item_name = $request->getParameter('item_name');
$cl_cost = $request->getParameter('cost');

// Elements of expensesForm.
$form = new Form('expensesForm');

if ($user->can('track_expenses')) {
  $rank = $user->getMaxRankForGroup($user->getGroup());
  if ($user->can('track_own_expenses'))
    $options = array('status'=>ACTIVE,'max_rank'=>$rank,'include_self'=>true,'self_first'=>true);
  else
    $options = array('status'=>ACTIVE,'max_rank'=>$rank);
  $user_list = $user->getUsers($options);
  if (count($user_list) >= 1) {
    $form->addInput(array('type'=>'combobox',
      'onchange'=>'this.form.user_changed.value=1;this.form.submit();',
      'name'=>'user',
      'style'=>'width: 250px;',
      'value'=>$user_id,
      'data'=>$user_list,
      'datakeys'=>array('id','name')));
    $form->addInput(array('type'=>'hidden','name'=>'user_changed'));
    $smarty->assign('user_dropdown', 1);
  }
}

// Dropdown for clients in MODE_TIME. Use all active clients.
if (MODE_TIME == $tracking_mode && $user->isPluginEnabled('cl')) {
    $active_clients = ttGroupHelper::getActiveClients(true);
    $form->addInput(array('type'=>'combobox',
      'onchange'=>'fillProjectDropdown(this.value);',
      'name'=>'client',
      'style'=>'width: 250px;',
      'value'=>$cl_client,
      'data'=>$active_clients,
      'datakeys'=>array('id', 'name'),
      'empty'=>array(''=>$i18n->get('dropdown.select'))));
  // Note: in other modes the client list is filtered to relevant clients only. See below.
}

if ($show_project) {
  // Dropdown for projects assigned to user.
  $project_list = $user->getAssignedProjects();
  $form->addInput(array('type'=>'combobox',
    'name'=>'project',
    'style'=>'width: 250px;',
    'value'=>$cl_project,
    'data'=>$project_list,
    'datakeys'=>array('id','name'),
    'empty'=>array(''=>$i18n->get('dropdown.select'))));

  // Dropdown for clients if the clients plugin is enabled.
  if ($user->isPluginEnabled('cl')) {
    $active_clients = ttGroupHelper::getActiveClients(true);
    // We need an array of assigned project ids to do some trimming. 
    foreach($project_list as $project)
      $projects_assigned_to_user[] = $project['id'];

    // Build a client list out of active clients. Use only clients that are relevant to user.
    // Also trim their associated project list to only assigned projects (to user).
    foreach($active_clients as $client) {
      $projects_assigned_to_client = explode(',', $client['projects']);
      $intersection = array_intersect($projects_assigned_to_client, $projects_assigned_to_user);
      if ($intersection) {
        $client['projects'] = implode(',', $intersection);
        $client_list[] = $client;
      }
    }
    $form->addInput(array('type'=>'combobox',
      'onchange'=>'fillProjectDropdown(this.value);',
      'name'=>'client',
      'style'=>'width: 250px;',
      'value'=>$cl_client,
      'data'=>$client_list,
      'datakeys'=>array('id', 'name'),
      'empty'=>array(''=>$i18n->get('dropdown.select'))));
  }
}
// If predefined expenses are configured, add controls to select an expense and quantity.
$predefined_expenses = ttGroupHelper::getPredefinedExpenses();
if ($predefined_expenses) {
  $form->addInput(array('type'=>'combobox',
    'onchange'=>'recalculateCost();',
    'name'=>'predefined_expense',
    'style'=>'width: 250px;',
    'value'=>$cl_predefined_expense,
    'data'=>$predefined_expenses,
    'datakeys'=>array('id', 'name'),
    'empty'=>array(''=>$i18n->get('dropdown.select'))));
  $form->addInput(array('type'=>'text','onchange'=>'recalculateCost();','maxlength'=>'40','name'=>'quantity','style'=>'width: 100px;','value'=>$cl_quantity));
}
$form->addInput(array('type'=>'textarea','maxlength'=>'800','name'=>'item_name','style'=>'width: 250px; height:'.NOTE_INPUT_HEIGHT.'px;','value'=>$cl_item_name));
$form->addInput(array('type'=>'text','maxlength'=>'40','name'=>'cost','style'=>'width: 100px;','value'=>$cl_cost));
$form->addInput(array('type'=>'calendar','name'=>'date','highlight'=>'expenses','value'=>$cl_date)); // calendar
$form->addInput(array('type'=>'hidden','name'=>'browser_today','value'=>'')); // User current date, which gets filled in on btn_submit click.
$form->addInput(array('type'=>'submit','name'=>'btn_submit','onclick'=>'browser_today.value=get_date()','value'=>$i18n->get('button.submit')));

// Submit.
if ($request->isPost()) {
  if ($request->getParameter('btn_submit')) {
    // Validate user input.
    if ($user->isPluginEnabled('cl') && $user->isPluginEnabled('cm') && !$cl_client)
      $err->add($i18n->get('error.client'));
    if ($show_project && !$cl_project)
      $err->add($i18n->get('error.project'));
    if (!ttValidString($cl_item_name)) $err->add($i18n->get('error.field'), $i18n->get('label.comment'));
    if (!ttValidFloat($cl_cost)) $err->add($i18n->get('error.field'), $i18n->get('label.cost'));

    // Prohibit creating entries in future.
    if (!$user->getConfigOption('future_entries')) {
      $browser_today = new DateAndTime(DB_DATEFORMAT, $request->getParameter('browser_today', null));
      if ($selected_date->after($browser_today))
        $err->add($i18n->get('error.future_date'));
    }
    if (!ttTimeHelper::canAdd()) $err->add($i18n->get('error.expired'));
    // Finished validating input data.

    // Prohibit creating entries in locked range.
    if ($user->isDateLocked($selected_date))
      $err->add($i18n->get('error.range_locked'));

    // Insert record.
    if ($err->no()) {
      if (ttExpenseHelper::insert(array('date'=>$cl_date,'client_id'=>$cl_client,
          'project_id'=>$cl_project,'name'=>$cl_item_name,'cost'=>$cl_cost,'status'=>1))) {
        header('Location: expenses.php');
        exit();
      } else
        $err->add($i18n->get('error.db'));
    }
  }
}

$smarty->assign('forms', array($form->getName()=>$form->toArray()));
$smarty->assign('show_project', $show_project);
$smarty->assign('day_total', ttExpenseHelper::getTotalForDay($cl_date));
$smarty->assign('expense_items', ttExpenseHelper::getItems($cl_date));
$smarty->assign('predefined_expenses', $predefined_expenses);
$smarty->assign('client_list', $client_list);
$smarty->assign('project_list', $project_list);
$smarty->assign('timestring', $selected_date->toString($user->getDateFormat()));
$smarty->assign('title', $i18n->get('title.expenses'));
$smarty->assign('content_page_name', 'expenses.tpl');
$smarty->display('index.tpl');
