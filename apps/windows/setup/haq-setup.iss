; The HAQ Retail OS setup program (D86, D88). One file that installs the whole shop on a Windows 10 (22H2) or
; 11 PC ("This PC runs the shop"), or makes a PC a counter of a shop whose server is another PC. It only asks
; the questions; the HAQ Helper's install engine (haq-helper setup) does the install, in a window the person
; installing watches. A second run on a PC that has it updates the Windows parts and keeps the shop.
;
; CI builds it, with the HAQ Helper and the haq-server distribution of the same commit:
;
;   ISCC /DAppVersion=2026.12.1 /DNumericVersion=2026.12.1.0 /DTag=2026.12.1
;        /DHelper=<haq-helper.exe> /DDistro=<haq-server.tar.gz> haq-setup.iss
;
; Silent, it asks nothing and installs from an answers file, as CI tries it:
;   HAQ-Retail-OS-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /MODE=counter /ANSWERS=<file>

#ifndef AppVersion
  #define AppVersion "development"
#endif
#ifndef NumericVersion
  #define NumericVersion "0.0.0.0"
#endif
#ifndef Tag
  #define Tag "main"
#endif
#ifndef Helper
  #error Give the HAQ Helper: /DHelper=<path to haq-helper.exe>
#endif
#ifndef Distro
  #error Give the distribution: /DDistro=<path to haq-server.tar.gz>
#endif

[Setup]
AppId={{6F1E4F2B-6C1D-4E5B-9A43-1B7B2E0C9D21}
AppName=HAQ Retail OS
AppVersion={#AppVersion}
AppVerName=HAQ Retail OS {#AppVersion}
AppPublisher=HAQ Technology
VersionInfoVersion={#NumericVersion}
DefaultDirName={autopf}\HAQ Retail OS
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0.19045
OutputBaseFilename=HAQ-Retail-OS-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=no
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
UninstallDisplayName=HAQ Retail OS
UninstallDisplayIcon={app}\haq-helper.exe

[Files]
Source: "{#Helper}"; DestDir: "{app}"; DestName: "haq-helper.exe"; Flags: ignoreversion
; The server's Linux, only where it is installed, and only for the install: {tmp} goes when the setup ends.
Source: "{#Distro}"; DestDir: "{tmp}"; DestName: "haq-server.tar.gz"; Flags: ignoreversion nocompression; Check: NeedsDistro

[UninstallRun]
; The Windows parts go; the shop and its sales stay in C:\ProgramData\HAQ (haq-helper setup remove).
Filename: "{app}\haq-helper.exe"; Parameters: "setup remove"; Flags: runhidden waituntilterminated; RunOnceId: "RemoveWindowsParts"

[Code]
const
  NeedsWSL = 20;

var
  ModePage: TInputOptionWizardPage;
  AddressPage: TInputQueryWizardPage;
  TokenPage: TInputQueryWizardPage;
  ShopPage: TInputQueryWizardPage;
  CountersPage: TInputQueryWizardPage;
  OwnerPage: TInputQueryWizardPage;
  ServerPage: TInputQueryWizardPage;
  PrinterPage: TInputOptionWizardPage;
  PrinterHostPage: TInputQueryWizardPage;
  Printers: TArrayOfString;
  Updating, Failed, ForceClose: Boolean;
  EnrolURL, PrinterLine: String;

{ --- Small helpers --- }

function Helper(): String;
begin
  Result := ExpandConstant('{tmp}\haq-helper.exe');
  if not FileExists(Result) then
    ExtractTemporaryFile('haq-helper.exe');
end;

function DataDir(): String;
begin
  Result := ExpandConstant('{commonappdata}\HAQ');
end;

{ Runs the helper hidden, its output into a file, and returns its exit code and output. }
function RunHelper(const Params: String; var Output: String): Integer;
var
  OutFile: String;
  Lines: AnsiString;
  Code: Integer;
begin
  OutFile := ExpandConstant('{tmp}\helper-output.txt');
  DeleteFile(OutFile);
  if not Exec(ExpandConstant('{cmd}'), '/c ""' + Helper() + '" ' + Params + ' > "' + OutFile + '" 2>&1"', '',
    SW_HIDE, ewWaitUntilTerminated, Code) then
    Code := -1;
  Result := Code;
  Output := '';
  if LoadStringFromFile(OutFile, Lines) then
    Output := Trim(String(Lines));
end;

function IsServer(): Boolean;
begin
  if WizardSilent() then
    Result := ExpandConstant('{param:MODE|}') = 'server'
  else
    Result := ModePage.SelectedValueIndex = 0;
end;

function NeedsDistro(): Boolean;
begin
  Result := (not Updating) and IsServer();
end;

function ChosenPrinter(): String;
begin
  Result := '';
  if (PrinterPage.SelectedValueIndex >= 0) and (PrinterPage.SelectedValueIndex < GetArrayLength(Printers)) then
    Result := Printers[PrinterPage.SelectedValueIndex];
end;

function NetworkPrinterChosen(): Boolean;
begin
  Result := PrinterPage.SelectedValueIndex = GetArrayLength(Printers);
end;

function JsonString(S: String): String;
begin
  StringChangeEx(S, '\', '\\', True);
  StringChangeEx(S, '"', '\"', True);
  StringChangeEx(S, #13, '\r', True);
  StringChangeEx(S, #10, '\n', True);
  StringChangeEx(S, #9, '\t', True);
  Result := '"' + S + '"';
end;

{ "T01, t03" as a JSON list: ["T01", "T03"]. }
function JsonCodes(S: String): String;
var
  Code: String;
  P: Integer;
begin
  Result := '';
  S := Uppercase(S) + ',';
  while S <> '' do
  begin
    P := Pos(',', S);
    Code := Trim(Copy(S, 1, P - 1));
    Delete(S, 1, P);
    if Code <> '' then
    begin
      if Result <> '' then
        Result := Result + ', ';
      Result := Result + JsonString(Code);
    end;
  end;
  Result := '[' + Result + ']';
end;

{ A value from the engine's result file, a flat JSON object of strings. }
function JsonValue(const Json, Key: String): String;
var
  P: Integer;
  Rest: String;
begin
  Result := '';
  P := Pos('"' + Key + '": "', Json);
  if P = 0 then
    Exit;
  Rest := Copy(Json, P + Length(Key) + 5, Length(Json));
  P := Pos('"', Rest);
  if P > 0 then
    Result := Copy(Rest, 1, P - 1);
end;

function OnlyDigits(const S: String): Boolean;
var
  I: Integer;
begin
  Result := S <> '';
  for I := 1 to Length(S) do
    if (S[I] < '0') or (S[I] > '9') then
      Result := False;
end;

{ --- The pages --- }

procedure InitializeWizard();
var
  Output: String;
  Lines: TStringList;
  I: Integer;
begin
  Updating := FileExists(DataDir() + '\installed');

  ModePage := CreateInputOptionPage(wpWelcome, 'What is this PC?',
    'A shop has one PC that runs it. Its other counters are PCs that use it.',
    'Choose what this PC will be, then click Next.', True, False);
  ModePage.Add('This PC runs the shop (the server, and the first counter, T01)');
  ModePage.Add('This PC is a counter of a shop whose server is another PC');
  ModePage.SelectedValueIndex := 0;

  AddressPage := CreateInputQueryPage(ModePage.ID, 'This PC''s address',
    'Every device in the shop reaches this PC at one fixed address.',
    'Give this PC a fixed IPv4 address on the shop''s network first (on the router, or in Windows'' network settings), then type it here.');
  AddressPage.Add('This PC''s fixed address:', False);

  TokenPage := CreateInputQueryPage(AddressPage.ID, 'The download token',
    'The shop server''s images are private. HAQ''s read-only token downloads them, now and for every update.',
    'It is asked only this once: the server keeps it.');
  TokenPage.Add('GitHub user name for the token:', False);
  TokenPage.Add('The token (classic, read:packages only):', True);

  ShopPage := CreateInputQueryPage(TokenPage.ID, 'The shop',
    'The receipts print these.', '');
  ShopPage.Add('Shop name:', False);
  ShopPage.Add('Name on the receipt, if different (at most 40 letters):', False);
  ShopPage.Add('Address on the receipt (optional):', False);
  ShopPage.Add('Phone on the receipt (optional):', False);
  ShopPage.Add('Receipt footer, such as the returns wording (optional):', False);

  CountersPage := CreateInputQueryPage(ShopPage.ID, 'The counters',
    'Each PC that sells is a counter: this one is T01, the next T02, and so on.', '');
  CountersPage.Add('How many counters:', False);
  CountersPage.Add('Counters with a cash drawer, such as T01, T02 (blank for none):', False);
  CountersPage.Values[0] := '1';
  CountersPage.Values[1] := 'T01';

  OwnerPage := CreateInputQueryPage(CountersPage.ID, 'The owner',
    'The owner signs in with their name tile and a 6-digit PIN, and adds everyone else in the back office.', '');
  OwnerPage.Add('The owner''s name, as on their tile:', False);
  OwnerPage.Add('The owner''s PIN (6 digits):', True);
  OwnerPage.Add('The same PIN again:', True);

  ServerPage := CreateInputQueryPage(AddressPage.ID, 'The shop''s server',
    'On the server''s PC, in Terminal (Admin), run: haq-helper server enrol T02 (for counter T02). It shows a code that works once, for 30 minutes.', '');
  ServerPage.Add('The server''s address:', False);
  ServerPage.Add('This counter (T02, T03 ...):', False);
  ServerPage.Add('The one-time code:', False);
  ServerPage.Values[1] := 'T02';

  PrinterPage := CreateInputOptionPage(OwnerPage.ID, 'The receipt printer',
    'Plug the receipt printer into this PC and switch it on before you choose.',
    'A USB printer prints through the HAQ print bridge on this PC; the setup prints a test receipt on it.', True, False);

  PrinterHostPage := CreateInputQueryPage(PrinterPage.ID, 'The network printer',
    'A receipt printer with its own network port has its own fixed address.', '');
  PrinterHostPage.Add('The printer''s address:', False);

  { The PC's addresses and printers, from the helper itself. }
  Lines := TStringList.Create;
  try
    if RunHelper('addresses', Output) = 0 then
    begin
      Lines.Text := Output;
      if Lines.Count > 0 then
        AddressPage.Values[0] := Trim(Lines.Strings[0]);
    end;
    if RunHelper('printers --names', Output) = 0 then
      Lines.Text := Output
    else
      Lines.Clear;
    SetArrayLength(Printers, 0);
    for I := 0 to Lines.Count - 1 do
      if Trim(Lines.Strings[I]) <> '' then
      begin
        SetArrayLength(Printers, GetArrayLength(Printers) + 1);
        Printers[GetArrayLength(Printers) - 1] := Trim(Lines.Strings[I]);
        PrinterPage.Add('On this PC: ' + Trim(Lines.Strings[I]));
      end;
  finally
    Lines.Free;
  end;
  PrinterPage.Add('A network printer, with its own address');
  PrinterPage.Add('No printer yet (receipts wait as Print pending until it is set)');
  PrinterPage.SelectedValueIndex := 0;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := False;
  if Updating then
    Result := (PageID = ModePage.ID) or (PageID = AddressPage.ID) or (PageID = TokenPage.ID) or
      (PageID = ShopPage.ID) or (PageID = CountersPage.ID) or (PageID = OwnerPage.ID) or
      (PageID = ServerPage.ID) or (PageID = PrinterPage.ID) or (PageID = PrinterHostPage.ID)
  else if IsServer() then
    Result := PageID = ServerPage.ID
  else
    Result := (PageID = TokenPage.ID) or (PageID = ShopPage.ID) or (PageID = CountersPage.ID) or (PageID = OwnerPage.ID);
  if (PageID = PrinterHostPage.ID) and not Updating then
    Result := not NetworkPrinterChosen();
end;

procedure CloseWizard(const Text: String);
begin
  MsgBox(Text, mbInformation, MB_OK);
  ForceClose := True;
  WizardForm.Close;
end;

procedure CancelButtonClick(CurPageID: Integer; var Cancel, Confirm: Boolean);
begin
  if ForceClose then
    Confirm := False;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Output, Mode: String;
  Code, N: Integer;
begin
  Result := True;
  if CurPageID = ModePage.ID then
  begin
    if IsServer() then Mode := 'server' else Mode := 'counter';
    Code := RunHelper('setup check --mode ' + Mode, Output);
    if Code = NeedsWSL then
    begin
      if MsgBox('This PC runs the shop in Windows'' own Linux (WSL), which is not turned on yet.' + #13#10#13#10 +
        'Click OK to turn it on now. Then restart Windows and run this setup again.',
        mbConfirmation, MB_OKCANCEL) = IDOK then
      begin
        Exec(Helper(), 'setup wsl', '', SW_SHOW, ewWaitUntilTerminated, Code);
        CloseWizard('Windows'' Linux is turned on. Restart Windows now, then run this setup again.');
      end;
      Result := False;
    end
    else if Code <> 0 then
    begin
      MsgBox(Output, mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = AddressPage.ID then
  begin
    if IsServer() then Mode := 'server' else Mode := 'counter';
    if RunHelper('setup check --mode ' + Mode + ' --address ' + Trim(AddressPage.Values[0]), Output) <> 0 then
    begin
      MsgBox(Output, mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = TokenPage.ID then
  begin
    if (Trim(TokenPage.Values[0]) = '') or (Trim(TokenPage.Values[1]) = '') then
    begin
      MsgBox('Give the GitHub user name and paste the whole token.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = ShopPage.ID then
  begin
    if Trim(ShopPage.Values[0]) = '' then
    begin
      MsgBox('Give the shop''s name.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = CountersPage.ID then
  begin
    N := StrToIntDef(Trim(CountersPage.Values[0]), 0);
    if (N < 1) or (N > 19) then
    begin
      MsgBox('A shop has 1 to 19 counters.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = OwnerPage.ID then
  begin
    if Trim(OwnerPage.Values[0]) = '' then
    begin
      MsgBox('Give the owner''s name.', mbError, MB_OK);
      Result := False;
    end
    else if (Length(OwnerPage.Values[1]) <> 6) or not OnlyDigits(OwnerPage.Values[1]) then
    begin
      MsgBox('The owner''s PIN is 6 digits.', mbError, MB_OK);
      Result := False;
    end
    else if OwnerPage.Values[1] <> OwnerPage.Values[2] then
    begin
      MsgBox('The two PINs differ. Type the same PIN twice.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = ServerPage.ID then
  begin
    if (Trim(ServerPage.Values[0]) = '') or (Trim(ServerPage.Values[1]) = '') or (Trim(ServerPage.Values[2]) = '') then
    begin
      MsgBox('Give the server''s address, this counter and the one-time code.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = PrinterPage.ID then
  begin
    if ChosenPrinter() <> '' then
    begin
      if RunHelper('test-print --printer "' + ChosenPrinter() + '"', Output) <> 0 then
      begin
        MsgBox('The test receipt did not print:' + #13#10#13#10 + Output, mbError, MB_OK);
        Result := False;
      end
      else
        Result := MsgBox('A test receipt went to ' + ChosenPrinter() + ', with a barcode and a QR code.' + #13#10#13#10 +
          'Did it print? Scan its codes with the scanner: the counter''s search box shows the same text.',
          mbConfirmation, MB_YESNO) = IDYES;
    end;
  end
  else if CurPageID = PrinterHostPage.ID then
  begin
    if Trim(PrinterHostPage.Values[0]) = '' then
    begin
      MsgBox('Give the printer''s address, such as 192.168.1.21.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo, MemoComponentsInfo,
  MemoGroupInfo, MemoTasksInfo: String): String;
var
  Printer: String;
begin
  if Updating then
  begin
    Result := 'This PC has HAQ Retail OS: the setup updates its Windows parts and keeps the shop.';
    Exit;
  end;
  if ChosenPrinter() <> '' then
    Printer := ChosenPrinter() + ', on this PC'
  else if NetworkPrinterChosen() then
    Printer := Trim(PrinterHostPage.Values[0]) + ', on the network'
  else
    Printer := 'none yet';
  if IsServer() then
    Result := 'This PC runs the shop, at ' + Trim(AddressPage.Values[0]) + '.' + NewLine +
      'Shop: ' + Trim(ShopPage.Values[0]) + NewLine +
      'Counters: ' + Trim(CountersPage.Values[0]) + '; with a drawer: ' + Trim(CountersPage.Values[1]) + NewLine +
      'Owner: ' + Trim(OwnerPage.Values[0]) + NewLine +
      'This counter''s printer: ' + Printer + NewLine + NewLine +
      'The install takes 15 to 40 minutes, most of it downloading. A window shows each step.'
  else
    Result := 'This PC is counter ' + Uppercase(Trim(ServerPage.Values[1])) + ', at ' + Trim(AddressPage.Values[0]) +
      ', of the shop whose server is at ' + Trim(ServerPage.Values[0]) + '.' + NewLine +
      'This counter''s printer: ' + Printer;
end;

{ --- The install --- }

function Answers(): String;
var
  Printer: String;
begin
  Printer := '';
  if ChosenPrinter() <> '' then
    Printer := ', "printer": ' + JsonString(ChosenPrinter())
  else if NetworkPrinterChosen() then
    Printer := ', "printer_host": ' + JsonString(Trim(PrinterHostPage.Values[0]));
  if IsServer() then
    Result := '{"mode": "server", "address": ' + JsonString(Trim(AddressPage.Values[0])) + Printer +
      ', "tag": ' + JsonString('{#Tag}') +
      ', "ghcr_user": ' + JsonString(Trim(TokenPage.Values[0])) + ', "ghcr_token": ' + JsonString(Trim(TokenPage.Values[1])) +
      ', "shop": {"name": ' + JsonString(Trim(ShopPage.Values[0])) + ', "receipt_name": ' + JsonString(Trim(ShopPage.Values[1])) +
      ', "address": ' + JsonString(Trim(ShopPage.Values[2])) + ', "phone": ' + JsonString(Trim(ShopPage.Values[3])) +
      ', "footer": ' + JsonString(Trim(ShopPage.Values[4])) + ', "counters": ' + IntToStr(StrToIntDef(Trim(CountersPage.Values[0]), 1)) +
      ', "drawers": ' + JsonCodes(CountersPage.Values[1]) + '}' +
      ', "owner_name": ' + JsonString(Trim(OwnerPage.Values[0])) + ', "owner_pin": ' + JsonString(OwnerPage.Values[1]) + '}'
  else
    Result := '{"mode": "counter", "address": ' + JsonString(Trim(AddressPage.Values[0])) + Printer +
      ', "server": ' + JsonString(Trim(ServerPage.Values[0])) + ', "counter": ' + JsonString(Uppercase(Trim(ServerPage.Values[1]))) +
      ', "code": ' + JsonString(Trim(ServerPage.Values[2])) + '}';
end;

{ Before a newer setup copies its files, the old helper stops the service and the keep-alive. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  Result := '';
  if FileExists(ExpandConstant('{app}\haq-helper.exe')) then
    Exec(ExpandConstant('{app}\haq-helper.exe'), 'setup stop', '', SW_HIDE, ewWaitUntilTerminated, Code);
end;

{ Runs the engine. Watched, its window stays open on a failure until a key is pressed; silent, it is hidden. }
function RunEngine(const Params: String): Integer;
var
  Exe: String;
  Code: Integer;
begin
  Exe := ExpandConstant('{app}\haq-helper.exe');
  if WizardSilent() then
  begin
    if not Exec(Exe, Params, '', SW_HIDE, ewWaitUntilTerminated, Code) then
      Code := -1;
  end
  else if not Exec(ExpandConstant('{cmd}'), '/c ""' + Exe + '" ' + Params + ' || (echo. & echo The install stopped: the lines above say why. & pause & exit /b 1)"',
    '', SW_SHOW, ewWaitUntilTerminated, Code) then
    Code := -1;
  Result := Code;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  AnswersFile, ResultFile, Json: String;
  Raw: AnsiString;
  Lines: TArrayOfString;
  Code: Integer;
begin
  if CurStep <> ssPostInstall then
    Exit;
  if Updating then
  begin
    Failed := RunEngine('setup update') <> 0;
    Exit;
  end;
  AnswersFile := ExpandConstant('{tmp}\answers.json');
  ResultFile := ExpandConstant('{tmp}\result.json');
  if WizardSilent() then
  begin
    { The answers hold the token: the copy goes once read, and the original now. }
    FileCopy(ExpandConstant('{param:ANSWERS|}'), AnswersFile, False);
    DeleteFile(ExpandConstant('{param:ANSWERS|}'));
  end
  else
  begin
    SetArrayLength(Lines, 1);
    Lines[0] := Answers();
    SaveStringsToUTF8File(AnswersFile, Lines, False);
  end;
  if IsServer() then
    Code := RunEngine('setup server --answers "' + AnswersFile + '" --distro "' + ExpandConstant('{tmp}\haq-server.tar.gz') +
      '" --result "' + ResultFile + '"')
  else
    Code := RunEngine('setup counter --answers "' + AnswersFile + '" --result "' + ResultFile + '"');
  DeleteFile(AnswersFile);
  Failed := Code <> 0;
  if (not Failed) and LoadStringFromFile(ResultFile, Raw) then
  begin
    Json := String(Raw);
    EnrolURL := JsonValue(Json, 'enrol_url');
    PrinterLine := JsonValue(Json, 'printer_line');
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
var
  Code: Integer;
  Text: String;
begin
  if CurPageID <> wpFinished then
    Exit;
  if Failed then
    Text := 'The install stopped before the end. Its window said why; the same is in ' + DataDir() + '\logs\setup.log.' + #13#10#13#10 +
      'Put right what it said, then run this setup again: it carries on, and keeps what is done.'
  else if Updating then
    Text := 'HAQ Retail OS is updated. The shop and its sales are as they were.'
  else
  begin
    Text := 'This PC is ready. The registration page opens now, with this counter''s code filled in: press Enrol counter.' + #13#10#13#10 +
      'From now on the counter opens full screen whenever Windows starts.';
    if PrinterLine <> '' then
      Text := Text + #13#10#13#10 + 'One more thing, on the server''s PC, in Terminal (Admin):' + #13#10 + PrinterLine;
    { Silent, nobody is there to enrol: the page is the installer's to open. }
    if (EnrolURL <> '') and not WizardSilent() then
      ShellExecAsOriginalUser('open', EnrolURL, '', '', SW_SHOWNORMAL, ewNoWait, Code);
  end;
  WizardForm.FinishedLabel.Caption := Text;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if (CurUninstallStep = usPostUninstall) and not UninstallSilent() then
    MsgBox('HAQ Retail OS is removed from Windows. The shop and its sales are kept in ' + ExpandConstant('{commonappdata}\HAQ') +
      ', and running the setup again brings them back. HAQ support can delete them for good.', mbInformation, MB_OK);
end;
