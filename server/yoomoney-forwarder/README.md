# Раздатчик уведомлений ЮMoney

## Зачем

У кошелька ЮMoney **один** адрес для уведомлений о платежах. Получателей
при этом двое:

* служба бота — `http://89.169.38.152:8000/yoomoney-webhook`;
* сайт — `https://genius-bot.ru/wp-json/genius/v1/yoomoney`.

Сейчас адрес указывает на бота. Бот отвечает `OK` на любую метку, в том
числе чужую (`topup_wp_…`, `kie-neurohub|…`), поэтому ЮMoney считает
уведомление доставленным, а сайт о платеже не узнаёт — баланс не
пополняется.

Раздатчик встаёт перед обоими и отдаёт уведомление слово в слово каждому.
Тело запроса не меняется, поэтому подпись остаётся верной и каждый
получатель проверяет её сам.

Своей логики внутри нет намеренно: ни разбора меток, ни начислений.

## Перед установкой: свободен ли порт

На сервере уже живут другие службы, и 8080 у них популярен. Проверьте:

```bash
ss -tlnp | grep -E ':(8090|8080) ' || echo 'порт свободен'
```

Если занят — впишите свободный в `ExecStart` модуля и подставьте его во все
команды ниже. По умолчанию здесь 8090.

## Установка на сервере 89.169.38.152

```bash
sudo mkdir -p /opt/yoomoney-forwarder
sudo curl -fsSL -o /opt/yoomoney-forwarder/forwarder.py \
  https://raw.githubusercontent.com/Aklex1/grandauto/claude/sounds-catalog-upload-o2wogb/server/yoomoney-forwarder/forwarder.py
sudo curl -fsSL -o /etc/systemd/system/yoomoney-forwarder.service \
  https://raw.githubusercontent.com/Aklex1/grandauto/claude/sounds-catalog-upload-o2wogb/server/yoomoney-forwarder/yoomoney-forwarder.service
sudo systemctl daemon-reload
sudo systemctl enable --now yoomoney-forwarder
curl -s http://127.0.0.1:8090/
```

Последняя строка должна ответить `yoomoney-forwarder`.

Если порт закрыт извне:

```bash
sudo ufw allow 8090/tcp    # ufw
# или
sudo firewall-cmd --add-port=8090/tcp --permanent && sudo firewall-cmd --reload
```

## Переключение кошелька

В ЮMoney → «Настройки» → «Уведомления о переводах» заменить адрес на:

```
http://89.169.38.152:8090/yoomoney-webhook
```

Секрет и галочку «Отправлять уведомления» не трогать: раздатчик передаёт
тело запроса целиком, подпись проверяют получатели.

## Непринятые уведомления

ЮMoney повторяет уведомление, только если ей ответили не «принято». Мы
отвечаем ей сразу и рассылаем сами — значит, повторять придётся нам.
Поэтому тело уведомления, которое получатель отверг, сохраняется в
`/var/lib/yoomoney-forwarder/failed/`, а не пропадает: иначе платёж
восстанавливать не из чего.

Сколько таких накопилось, видно на живости:

```bash
curl -s http://127.0.0.1:8090/
```

Когда причина отказа устранена (например, поправлен секрет подписи):

```bash
sudo systemd-run --uid=0 --property=StateDirectory=yoomoney-forwarder \
  /usr/bin/python3 /opt/yoomoney-forwarder/forwarder.py --replay
sudo journalctl -u yoomoney-forwarder --since "-5 min" --no-pager | tail
```

Принятое удаляется, остальное ждёт следующей попытки.

Сетевые сбои раздатчик переживает сам: четыре попытки с паузами до двух
минут. Отказ по сути запроса (подпись, адрес) не повторяется — от
ожидания он не изменится.

## Проверка

```bash
sudo journalctl -u yoomoney-forwarder -f
```

При платеже в журнале появится строка вида:

```
уведомление: метка topup_wp_12_1789957313, байт 312
доставлено http://127.0.0.1:8000/yoomoney-webhook: код 200
доставлено https://genius-bot.ru/wp-json/genius/v1/yoomoney: код 200
```

## Обновление

```bash
sudo curl -fsSL -o /opt/yoomoney-forwarder/forwarder.py \
  https://raw.githubusercontent.com/Aklex1/grandauto/claude/sounds-catalog-upload-o2wogb/server/yoomoney-forwarder/forwarder.py
sudo systemctl restart yoomoney-forwarder
```

## Что делать, если раздатчик ставить некуда

Есть путь без установки вообще: указать в кошельке адрес сайта

```
https://genius-bot.ru/wp-json/genius/v1/yoomoney
```

Сайт сам разбирает метку: свои (`topup_wp_…`, `topup_telegram_…`)
обрабатывает, чужие (`topup_<tgid>_…` бота) пересылает на
`http://89.169.38.152:8000/yoomoney-webhook`. Получается то же самое, но
без новой службы. Минус один: если сайт лежит, уведомления бота не
доходят, — тогда как сейчас, но наоборот.
