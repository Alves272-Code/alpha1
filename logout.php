<?php
session_start();
session_destroy();
require 'functions.php';
redirecionar('index.php');