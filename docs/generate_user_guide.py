"""
Генератор PDF-инструкции по юнит-экономике для клиентского менеджера.

Запуск:
    python3 docs/generate_user_guide.py
"""
from pathlib import Path

from reportlab.lib.colors import HexColor, white, black
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm, mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    HRFlowable,
    Image,
    KeepTogether,
    ListFlowable,
    ListItem,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

# ——— Шрифты ———
# Arial на macOS поддерживает кириллицу
pdfmetrics.registerFont(TTFont("Arial", "/System/Library/Fonts/Supplemental/Arial.ttf"))
pdfmetrics.registerFont(TTFont("Arial-Bold", "/System/Library/Fonts/Supplemental/Arial Bold.ttf"))
pdfmetrics.registerFont(TTFont("Arial-Italic", "/System/Library/Fonts/Supplemental/Arial Italic.ttf"))
pdfmetrics.registerFontFamily(
    "Arial",
    normal="Arial",
    bold="Arial-Bold",
    italic="Arial-Italic",
    boldItalic="Arial-Bold",
)

# ——— Цвета ———
COLOR_PRIMARY = HexColor("#1E40AF")  # насыщенный синий
COLOR_ACCENT = HexColor("#059669")  # зелёный для плюсов
COLOR_WARN = HexColor("#DC2626")  # красный для минусов
COLOR_BG_LIGHT = HexColor("#F3F4F6")  # серый фон
COLOR_BG_BLUE = HexColor("#DBEAFE")  # светло-синий
COLOR_BG_GREEN = HexColor("#D1FAE5")
COLOR_BG_RED = HexColor("#FEE2E2")
COLOR_BORDER = HexColor("#D1D5DB")
COLOR_TEXT_MUTED = HexColor("#6B7280")

# ——— Стили ———
styles = getSampleStyleSheet()

style_body = ParagraphStyle(
    "Body",
    parent=styles["Normal"],
    fontName="Arial",
    fontSize=11,
    leading=16,
    spaceAfter=6,
    alignment=TA_LEFT,
    textColor=HexColor("#111827"),
)

style_intro = ParagraphStyle(
    "Intro",
    parent=style_body,
    fontSize=12,
    leading=18,
    spaceAfter=10,
    alignment=TA_JUSTIFY,
)

style_h1 = ParagraphStyle(
    "H1",
    parent=styles["Heading1"],
    fontName="Arial-Bold",
    fontSize=22,
    leading=28,
    textColor=COLOR_PRIMARY,
    spaceBefore=8,
    spaceAfter=14,
    alignment=TA_LEFT,
)

style_h2 = ParagraphStyle(
    "H2",
    parent=styles["Heading2"],
    fontName="Arial-Bold",
    fontSize=16,
    leading=22,
    textColor=COLOR_PRIMARY,
    spaceBefore=16,
    spaceAfter=8,
)

style_h3 = ParagraphStyle(
    "H3",
    parent=styles["Heading3"],
    fontName="Arial-Bold",
    fontSize=13,
    leading=18,
    textColor=HexColor("#1F2937"),
    spaceBefore=12,
    spaceAfter=6,
)

style_title = ParagraphStyle(
    "Title",
    parent=styles["Title"],
    fontName="Arial-Bold",
    fontSize=30,
    leading=36,
    textColor=COLOR_PRIMARY,
    alignment=TA_CENTER,
    spaceAfter=8,
)

style_subtitle = ParagraphStyle(
    "Subtitle",
    parent=style_body,
    fontSize=14,
    leading=20,
    textColor=COLOR_TEXT_MUTED,
    alignment=TA_CENTER,
    spaceAfter=20,
)

style_callout = ParagraphStyle(
    "Callout",
    parent=style_body,
    fontSize=11,
    leading=15,
    textColor=HexColor("#1F2937"),
    leftIndent=8,
    rightIndent=8,
    spaceBefore=4,
    spaceAfter=4,
)

style_code = ParagraphStyle(
    "Code",
    parent=style_body,
    fontName="Courier",
    fontSize=10,
    leading=14,
    leftIndent=14,
    rightIndent=14,
    backColor=COLOR_BG_LIGHT,
    borderColor=COLOR_BORDER,
    borderWidth=0.5,
    borderPadding=8,
    spaceBefore=6,
    spaceAfter=10,
)

style_small = ParagraphStyle(
    "Small",
    parent=style_body,
    fontSize=9,
    leading=12,
    textColor=COLOR_TEXT_MUTED,
)


def callout(text: str, bg_color, text_color=black, icon: str = ""):
    """Красивая подсветка-блок с фоном."""
    prefix = f"<b>{icon}</b> " if icon else ""
    para = Paragraph(f"{prefix}{text}", style_callout)
    table = Table([[para]], colWidths=[16 * cm])
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), bg_color),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("LEFTPADDING", (0, 0), (-1, -1), 12),
                ("RIGHTPADDING", (0, 0), (-1, -1), 12),
                ("TOPPADDING", (0, 0), (-1, -1), 10),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 10),
                ("ROUNDEDCORNERS", [6, 6, 6, 6]),
            ]
        )
    )
    return table


def bullet_list(items: list[str]):
    return ListFlowable(
        [ListItem(Paragraph(it, style_body), leftIndent=14, bulletColor=COLOR_PRIMARY) for it in items],
        bulletType="bullet",
        start="•",
        leftIndent=14,
    )


def kv_table(rows: list[tuple[str, str]], col_widths=(6 * cm, 10 * cm)):
    data = [[Paragraph(f"<b>{k}</b>", style_body), Paragraph(v, style_body)] for k, v in rows]
    table = Table(data, colWidths=col_widths)
    table.setStyle(
        TableStyle(
            [
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("BACKGROUND", (0, 0), (0, -1), COLOR_BG_LIGHT),
                ("TEXTCOLOR", (0, 0), (0, -1), HexColor("#111827")),
                ("GRID", (0, 0), (-1, -1), 0.3, COLOR_BORDER),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]
        )
    )
    return table


def calculation_table():
    """Наглядный пример расчёта — таблица «доход минус расходы»."""
    header_style = ParagraphStyle(
        "TableHead", parent=style_body, fontName="Arial-Bold", fontSize=10, textColor=white
    )
    cell_style = ParagraphStyle("TableCell", parent=style_body, fontSize=10, leading=14)
    cell_style_right = ParagraphStyle(
        "TableCellRight", parent=cell_style, alignment=2  # right
    )
    plus_style = ParagraphStyle(
        "Plus", parent=cell_style_right, textColor=COLOR_ACCENT, fontName="Arial-Bold"
    )
    minus_style = ParagraphStyle(
        "Minus", parent=cell_style_right, textColor=COLOR_WARN
    )
    total_style = ParagraphStyle(
        "Total", parent=cell_style_right, fontName="Arial-Bold", fontSize=11
    )

    data = [
        [
            Paragraph("<b>Статья</b>", header_style),
            Paragraph("<b>Формула</b>", header_style),
            Paragraph("<b>Сумма</b>", header_style),
        ],
        [
            Paragraph("Цена продажи", cell_style),
            Paragraph("— (цена в карточке)", cell_style),
            Paragraph("+ 2 990 ₽", plus_style),
        ],
        [
            Paragraph("Себестоимость", cell_style),
            Paragraph("— (ваша закупочная цена)", cell_style),
            Paragraph("− 1 200 ₽", minus_style),
        ],
        [
            Paragraph("Упаковка", cell_style),
            Paragraph("— (коробка+пупырка)", cell_style),
            Paragraph("− 20 ₽", minus_style),
        ],
        [
            Paragraph("Комиссия Ozon FBO (14%)", cell_style),
            Paragraph("2 990 × 14%", cell_style),
            Paragraph("− 418,60 ₽", minus_style),
        ],
        [
            Paragraph("Эквайринг (1,5%)", cell_style),
            Paragraph("2 990 × 1,5%", cell_style),
            Paragraph("− 44,85 ₽", minus_style),
        ],
        [
            Paragraph("Логистика (средневзвешенная)", cell_style),
            Paragraph("по кластерам", cell_style),
            Paragraph("− 206 ₽", minus_style),
        ],
        [
            Paragraph("Надбавка за нелокальную продажу", cell_style),
            Paragraph("2 990 × 3,2%", cell_style),
            Paragraph("− 95,68 ₽", minus_style),
        ],
        [
            Paragraph("Последняя миля", cell_style),
            Paragraph("фикс (25 ₽)", cell_style),
            Paragraph("− 25 ₽", minus_style),
        ],
        [
            Paragraph("Резерв на невыкупы (15%)", cell_style),
            Paragraph("(206 + 15) × 15%", cell_style),
            Paragraph("− 33,15 ₽", minus_style),
        ],
        [
            Paragraph("Налог УСН (6%)", cell_style),
            Paragraph("2 990 × 6%", cell_style),
            Paragraph("− 179,40 ₽", minus_style),
        ],
        [
            Paragraph("Реклама / ДРР (10%)", cell_style),
            Paragraph("2 990 × 10%", cell_style),
            Paragraph("− 299 ₽", minus_style),
        ],
        [
            Paragraph("<b>ЧИСТАЯ ПРИБЫЛЬ</b>", cell_style),
            Paragraph("<b>итого</b>", cell_style),
            Paragraph("<b>= 468,32 ₽</b>", total_style),
        ],
        [
            Paragraph("<b>МАРЖА</b>", cell_style),
            Paragraph("прибыль / цена", cell_style),
            Paragraph("<b>15,7%</b>", total_style),
        ],
    ]

    table = Table(data, colWidths=(7 * cm, 6 * cm, 3.5 * cm))
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), COLOR_PRIMARY),
                ("TEXTCOLOR", (0, 0), (-1, 0), white),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("GRID", (0, 0), (-1, -1), 0.3, COLOR_BORDER),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                ("ROWBACKGROUNDS", (0, 1), (-1, -3), [white, COLOR_BG_LIGHT]),
                ("BACKGROUND", (0, -2), (-1, -1), COLOR_BG_GREEN),
            ]
        )
    )
    return table


def markup_table():
    """Таблица надбавок по кластерам."""
    header_style = ParagraphStyle(
        "TableHead", parent=style_body, fontName="Arial-Bold", fontSize=10, textColor=white
    )
    cell_style = ParagraphStyle("TableCell", parent=style_body, fontSize=10, leading=14)

    def row(cluster, markup, color_bg):
        return [
            Paragraph(cluster, cell_style),
            Paragraph(f"<b>{markup}</b>", ParagraphStyle("c", parent=cell_style, alignment=1, fontName="Arial-Bold")),
        ]

    data = [
        [Paragraph("<b>Кластер покупателя</b>", header_style), Paragraph("<b>Надбавка</b>", header_style)],
        row("Краснодар, Новосибирск, Ростов-на-Дону, Ярославль", "0%", COLOR_BG_GREEN),
        row("Воронеж", "4%", COLOR_BG_LIGHT),
        row("Беларусь, Казахстан, Киргизия", "6%", COLOR_BG_LIGHT),
        row("Москва/МО, СПб/СЗО, Казань, Екатеринбург, Дальний Восток, Калининград", "8%", COLOR_BG_RED),
    ]

    t = Table(data, colWidths=(12.5 * cm, 4 * cm))
    t.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), COLOR_PRIMARY),
                ("TEXTCOLOR", (0, 0), (-1, 0), white),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("GRID", (0, 0), (-1, -1), 0.3, COLOR_BORDER),
                ("LEFTPADDING", (0, 0), (-1, -1), 8),
                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                ("TOPPADDING", (0, 0), (-1, -1), 8),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
                ("BACKGROUND", (0, 1), (-1, 1), COLOR_BG_GREEN),
                ("BACKGROUND", (0, -1), (-1, -1), COLOR_BG_RED),
            ]
        )
    )
    return t


# ——— Генерация PDF ———
OUTPUT = Path(__file__).parent / "USER_GUIDE_UNIT_ECONOMICS.pdf"

doc = SimpleDocTemplate(
    str(OUTPUT),
    pagesize=A4,
    leftMargin=2 * cm,
    rightMargin=2 * cm,
    topMargin=1.8 * cm,
    bottomMargin=1.8 * cm,
    title="Юнит-экономика: руководство для менеджера",
    author="products-backend",
)

story = []

# ——— ТИТУЛЬНАЯ ———
story.append(Spacer(1, 4 * cm))
story.append(Paragraph("Юнит-экономика", style_title))
story.append(Paragraph("Простое руководство для менеджера клиента", style_subtitle))
story.append(Spacer(1, 1 * cm))
story.append(
    callout(
        "Этот документ объясняет, что такое юнит-экономика, откуда берутся все цифры в таблице и что делать с каждой из них. Без технических терминов — как разговор за чашкой кофе.",
        COLOR_BG_BLUE,
    )
)
story.append(Spacer(1, 2 * cm))
story.append(
    Paragraph(
        "<i>Время на чтение: 15 минут.<br/>Весь раздел — 10 страниц.</i>",
        ParagraphStyle("c", parent=style_body, alignment=TA_CENTER, textColor=COLOR_TEXT_MUTED),
    )
)
story.append(PageBreak())

# ——— 1. ЧТО ЭТО ТАКОЕ ———
story.append(Paragraph("1. Что такое юнит-экономика", style_h1))
story.append(
    Paragraph(
        "Юнит-экономика — это ответ на главный вопрос бизнеса: "
        "<b>«Я продал одну штуку. Сколько я с этого заработал?»</b>",
        style_intro,
    )
)

story.append(
    Paragraph(
        "Звучит просто, но на маркетплейсе не работает математика «цена минус закупка». "
        "Между вами и покупателем стоит Ozon (или WB, или Яндекс), и он берёт деньги "
        "<b>15 разных способов</b>: комиссия с продажи, логистика по России, надбавка за "
        "нелокальную продажу, последняя миля, эквайринг, обратная логистика за невыкупы "
        "и так далее. Плюс налоги, плюс реклама, плюс упаковка.",
        style_body,
    )
)
story.append(
    Paragraph(
        "Юнит-экономика — это инструмент, который <b>собирает все эти расходы за вас</b> "
        "и показывает: <i>«На этом товаре ты зарабатываешь 468 рублей с штуки, это 15,7% "
        "от цены. А вот этот товар — в минус 80 рублей, снимай его с продажи или поднимай цену»</i>.",
        style_body,
    )
)

story.append(Spacer(1, 6))
story.append(
    callout(
        "<b>Главная формула, которую надо запомнить:</b><br/><br/>"
        "<b>Прибыль = Цена продажи − Все расходы</b><br/><br/>"
        "Маржа (в %) = Прибыль ÷ Цена × 100",
        COLOR_BG_BLUE,
    )
)

story.append(Paragraph("Зачем это нужно", style_h2))
story.append(
    bullet_list(
        [
            "<b>Понять, на чём вы зарабатываете</b>, а на чём теряете — по каждой карточке товара.",
            "<b>Принимать решения:</b> снять товар с продажи, поднять цену, сменить поставщика, подключить акцию.",
            "<b>Сравнивать схемы:</b> FBO против FBS против realFBS — что выгоднее для этого SKU.",
            "<b>Проверять акции:</b> система покажет маржу при скидке 30% — если уходит в минус, значит акция сжирает прибыль.",
            "<b>Планировать рекламу:</b> видно, сколько процентов ДРР (расхода на рекламу) вы можете себе позволить.",
        ]
    )
)

story.append(PageBreak())

# ——— 2. ГДЕ НАЙТИ ———
story.append(Paragraph("2. Где это найти в системе", style_h1))
story.append(
    Paragraph(
        "В левом меню кабинета — раздел <b>«Юнит-экономика»</b>. "
        "Внутри — вкладки по маркетплейсам: <b>Ozon</b>, <b>Wildberries</b>, <b>Яндекс.Маркет</b>. "
        "Выбираете нужный и видите таблицу со всеми своими товарами.",
        style_body,
    )
)
story.append(Spacer(1, 6))

story.append(Paragraph("Что вы увидите в таблице", style_h3))
story.append(
    kv_table(
        [
            ("SKU и фото товара", "чтобы быстро опознать карточку"),
            ("Цена", "текущая цена в маркетплейсе"),
            ("Себестоимость", "ваша закупочная цена (её вбиваете вы)"),
            ("Маржа в ₽", "чистая прибыль с одной штуки"),
            ("Маржа в %", "доля прибыли от цены"),
            ("Индикатор 🟢 / 🔴", "зелёный — прибыль; красный — убыток"),
            ("% выкупа", "сколько покупателей реально забирают заказ"),
            ("Схема", "FBO / FBS / realFBS"),
            ("Источник данных", "real_orders / delivery_analytics / repo_fallback"),
            ("Уверенность", "high / medium / low — насколько точный расчёт"),
        ]
    )
)

story.append(Spacer(1, 10))
story.append(
    callout(
        "<b>💡 Совет:</b> первое, на что надо смотреть — колонка <b>«Маржа %»</b>. "
        "Отсортируйте по ней возрастающе — самые убыточные товары окажутся наверху. "
        "С ними и работайте в первую очередь.",
        COLOR_BG_BLUE,
    )
)

story.append(PageBreak())

# ——— 3. ПЕРВЫЙ ЗАПУСК ———
story.append(Paragraph("3. Что сделать в первый раз", style_h1))
story.append(
    Paragraph(
        "Система знает цены с маркетплейса, знает все комиссии и логистику, считает налог, "
        "считает выкуп по реальным заказам. Но <b>одну цифру она не знает</b> — сколько "
        "товар стоит вам самим. Её нужно вбить. Без неё маржа считается с пустой "
        "себестоимостью и цифры будут врать.",
        style_body,
    )
)

story.append(Paragraph("Вариант 1. Через Excel (когда товаров много)", style_h3))
story.append(
    bullet_list(
        [
            "Откройте «Юнит-экономика» → вкладка «Себестоимость»",
            "Нажмите «Скачать шаблон». Получите Excel с колонками <b>SKU | Себестоимость | Упаковка</b>",
            "Заполните себестоимость и сохраните файл",
            "Нажмите «Загрузить» — файл загрузится и обновит данные за несколько секунд",
        ]
    )
)

story.append(Paragraph("Вариант 2. По одному товару (когда меняется цена закупки)", style_h3))
story.append(
    bullet_list(
        [
            "Найдите товар в таблице (через поиск по SKU или названию)",
            "Кликните по строке → откроется карточка с детальным расчётом",
            "Нажмите «Редактировать» в блоке «Настройки»",
            "Измените себестоимость → «Сохранить»",
            "Маржа пересчитается <b>автоматически</b>, через несколько секунд новая цифра появится в таблице",
        ]
    )
)

story.append(Spacer(1, 6))
story.append(
    callout(
        "<b>⚠️ Важно:</b> указывайте <b>закупочную цену без НДС</b>, если работаете по УСН (большинство продавцов). "
        "Если по ОСН — с НДС. Эту цифру вам даёт поставщик.",
        COLOR_BG_RED,
    )
)

story.append(Paragraph("Что ещё можно вбить", style_h3))
story.append(
    bullet_list(
        [
            "<b>Упаковка</b> — сколько тратите на коробку/пупырку/бирку (обычно 5–50 ₽)",
            "<b>ДРР (%)</b> — средний процент от выручки, который уходит на рекламу (например, 10%)",
            "<b>Доля партнёра (%)</b> — если есть соинвестор или процент партнёру с продаж",
            "<b>Налог (%)</b> — по умолчанию 6% (УСН Доходы). Меняйте если у вас другой режим",
            "<b>% выкупа</b> — можно задать вручную, если система ещё не собрала достаточно данных",
        ]
    )
)

story.append(PageBreak())

# ——— 4. КАК ЧИТАТЬ ———
story.append(Paragraph("4. Как читать таблицу", style_h1))

story.append(Paragraph("🟢 Зелёная строчка", style_h3))
story.append(
    Paragraph(
        "Маржа в плюсе — товар зарабатывает. Смотрите на процент: "
        "<b>20% и выше</b> — хорошо, <b>10–20%</b> — нормально, <b>ниже 10%</b> — рискованно "
        "(любое изменение цены, акция или изменение тарифа уведёт в минус).",
        style_body,
    )
)

story.append(Paragraph("🔴 Красная строчка", style_h3))
story.append(
    Paragraph(
        "Товар продаётся в минус. Варианты действий:",
        style_body,
    )
)
story.append(
    bullet_list(
        [
            "<b>Поднять цену</b> — если рынок позволяет и ваш товар не «на грани» конкурентов",
            "<b>Снизить себестоимость</b> — переговорить с поставщиком, купить объёмом",
            "<b>Снять с продажи</b> — если не получается первые два варианта",
            "<b>Сменить схему</b> — иногда FBS даёт плюс там, где FBO в минусе (или наоборот)",
            "<b>Сократить ДРР</b> — если вы тратите 20% на рекламу, а маржа чистая −5%, уберите рекламу",
        ]
    )
)

story.append(Paragraph("Особые метки в таблице", style_h3))
story.append(
    kv_table(
        [
            ("🏷 «оценка»", "данных мало (менее 10 заказов), цифры приблизительные"),
            ("⚠️ «нет себестоимости»", "не вбита закупочная цена — маржа считается неполной"),
            ("🧪 «новый товар»", "товар недавно появился, надо подождать продаж"),
            ("❄️ «замороженный»", "нет продаж более 60 дней"),
            ("🔒 «фиксация»", "товар по заявке на поставку с зафиксированными тарифами (60 дней)"),
        ]
    )
)

story.append(PageBreak())

# ——— 5. ПРИМЕР РАСЧЁТА ———
story.append(Paragraph("5. Наглядный пример: как считается маржа", style_h1))
story.append(
    Paragraph(
        "<b>Исходные данные:</b> сумка за 2 990 ₽, закупили за 1 200 ₽, объём 5 л. "
        "Магазин в Москве. За 30 дней 50 заказов: 60% в Москву, 20% в СПб, 20% в Казань. "
        "Выкуп — 85%. Упаковка 20 ₽. ДРР 10%. Налог 6% (УСН).",
        style_body,
    )
)
story.append(Spacer(1, 10))
story.append(calculation_table())
story.append(Spacer(1, 10))
story.append(
    callout(
        "<b>Вывод:</b> на каждой проданной сумке вы оставляете в кармане <b>468 рублей</b>. "
        "Это 15,7% маржи — нормальный результат. Если поднимете цену до 3 200 ₽, маржа "
        "вырастет до 20%. Если включите акцию −20% (цена 2 392 ₽) — маржа упадёт до 3%, "
        "почти ноль.",
        COLOR_BG_GREEN,
    )
)

story.append(PageBreak())

# ——— 6. ЛОГИСТИКА ———
story.append(Paragraph("6. Логистика — главная головоломка", style_h1))
story.append(
    Paragraph(
        "Логистика — самая сложная часть расчёта, поэтому объясним отдельно.",
        style_body,
    )
)
story.append(
    Paragraph(
        "<b>С 6 апреля 2026 года Ozon работает по новым правилам.</b> Нет больше «индекса "
        "локализации» и «среднего времени доставки». Теперь — фиксированные тарифы "
        "по парам городов (<i>откуда</i> → <i>куда</i>) и объёму товара в литрах.",
        style_body,
    )
)

story.append(Paragraph("Как работает расчёт", style_h3))
story.append(
    bullet_list(
        [
            "Система смотрит <b>куда реально покупают</b> ваш товар за последние 30 дней",
            "Для каждого направления считает свою логистику и свою надбавку",
            "Всё усредняется взвешенно — в итоговую таблицу попадает «средний» показатель",
        ]
    )
)

story.append(Paragraph("Надбавка за нелокальную продажу (0–8%)", style_h3))
story.append(
    Paragraph(
        "Если покупатель и ваш склад в <b>разных регионах</b> — Ozon берёт дополнительный "
        "процент от цены. Вот ставки:",
        style_body,
    )
)
story.append(Spacer(1, 6))
story.append(markup_table())
story.append(Spacer(1, 10))

story.append(Paragraph("Когда надбавка НЕ применяется", style_h3))
story.append(
    bullet_list(
        [
            "Продажа внутри своего кластера (товар и покупатель в одном регионе)",
            "Заказ отменили или покупатель не выкупил",
            "У продавца <b>меньше 50 FBO-заказов</b> за последние 7 дней",
            "Товар продаётся только на площадке Ozon Select",
            "Крупногабарит или ювелирные изделия (нельзя разместить в локальном складе)",
        ]
    )
)

story.append(Spacer(1, 10))
story.append(
    callout(
        "<b>💡 Практический совет:</b> если ваш товар часто покупают в Москве и Екатеринбурге, "
        "выгоднее <b>отгрузить часть на екатеринбургский склад Ozon</b>, а не возить всё из "
        "Москвы. Так уберёте 8% надбавки на уральские заказы. Именно для этого в системе "
        "есть раздел «Автопланирование» — он считает, что и куда везти.",
        COLOR_BG_BLUE,
    )
)

story.append(PageBreak())

# ——— 7. АВТООБНОВЛЕНИЕ ———
story.append(Paragraph("7. Когда всё это пересчитывается", style_h1))
story.append(
    Paragraph(
        "Вам не нужно жать кнопку «Обновить». Система сама следит за изменениями "
        "и пересчитывает маржу в фоне. Обычно пересчёт одного товара занимает несколько секунд, "
        "всей интеграции — до 5 минут.",
        style_body,
    )
)

story.append(Paragraph("Триггеры пересчёта", style_h3))
story.append(
    kv_table(
        [
            ("Вы вбили себестоимость", "пересчёт этого SKU за 2–3 секунды"),
            ("Изменили ДРР/налог/упаковку", "пересчёт за несколько секунд"),
            ("Маркетплейс изменил цену", "при ближайшей синхронизации товаров"),
            ("Прошла синхронизация остатков", "обновляется процент выкупа и локальность"),
            ("Обновился профиль доставки Ozon", "автоматически, от API Ozon"),
            ("Создана/отменена заявка на поставку", "обновляется фиксация тарифов"),
        ]
    )
)

story.append(Spacer(1, 8))
story.append(
    Paragraph(
        "Если сильно надо пересчитать прямо сейчас — есть кнопка <b>«Пересчитать»</b> в шапке "
        "таблицы. Она запускает полный пересчёт всей интеграции. Используйте в крайнем случае: "
        "обычно автопересчёт справляется сам.",
        style_body,
    )
)

story.append(PageBreak())

# ——— 8. ЧАСТЫЕ ВОПРОСЫ ———
story.append(Paragraph("8. Частые вопросы", style_h1))

faqs = [
    (
        "У меня в таблице стоит «нет себестоимости» — я же вбивал её для всех!",
        "Проверьте, не появились ли новые SKU после последней синхронизации товаров. "
        "Скорее всего, маркетплейс подтянул новые карточки, для которых себестоимость "
        "ещё не введена. Загрузите их через Excel-шаблон.",
    ),
    (
        "Почему маржа у двух одинаковых товаров разная?",
        "Чаще всего — из-за разной локальности. Товар на московском складе и тот же товар "
        "на казанском складе будут иметь разную среднюю надбавку, потому что их покупают "
        "в разных регионах. Также разные тарифы комиссии по категориям.",
    ),
    (
        "У товара стоит «low» уверенность — это проблема?",
        "Нет, это просто честное признание, что данных мало. Как только накопится 10+ заказов, "
        "уверенность поднимется до «medium» или «high», а цифры станут точнее. До тех пор "
        "не принимайте серьёзных решений (типа снятия с продажи) на основании этой строчки.",
    ),
    (
        "В таблице видно, что товар в минусе. Что делать в первую очередь?",
        "Посмотрите детальный расчёт (клик по строке). Там будет видна самая большая статья "
        "расхода. Если это логистика — возможно, надо перенести склад. Если комиссия — "
        "дешёвые товары (до 300 ₽) часто не окупаются на маркетплейсах, иногда это "
        "структурная проблема. Если ДРР — уменьшите рекламу. Если себестоимость близка к цене — "
        "надо работать с поставщиком.",
    ),
    (
        "Можно ли посчитать, что будет при скидке 30%?",
        "Да. В карточке товара есть блок «Симуляция» — вводите цену и процент скидки, "
        "видите новую маржу. Без сохранения в БД. Удобно использовать перед запуском акции.",
    ),
    (
        "Цифры в таблице отличаются от того, что приходит на расчётный счёт. Где правда?",
        "Юнит-экономика показывает <b>ожидаемую</b> маржу в модели: с усреднёнными "
        "тарифами и резервом на невыкупы. Реальная сумма на счёте зависит от конкретных "
        "отгрузок в конкретные даты. Расхождение 5–10% — норма. Если больше — "
        "возможно, маркетплейс поменял тарифы, а мы ещё не обновили — напишите в поддержку.",
    ),
    (
        "Что значит «фиксация» у товара?",
        "Когда вы создаёте заявку на поставку в Ozon, платформа фиксирует вам тарифы "
        "на 60 дней. Это значит, что даже если Ozon повысит цены, для вашей поставки "
        "будет применяться старый тариф. Система учитывает это: для товаров с активной "
        "фиксацией показывается фактический (зафиксированный) тариф, а не текущий.",
    ),
    (
        "Почему маржа показывает расчёт «в среднем на заказ», а не только на выкупленные?",
        "Потому что невыкупы — ваши расходы тоже. За каждый невыкуп вы платите логистику в "
        "обе стороны. Если считать маржу только на выкупленных, получится красивая, но "
        "обманчивая цифра. Мы «размазываем» стоимость невыкупов по всем заказам — так "
        "вы видите реальную прибыль за весь поток.",
    ),
]

for q, a in faqs:
    story.append(Paragraph(f"<b>Q: {q}</b>", style_h3))
    story.append(Paragraph(a, style_body))
    story.append(Spacer(1, 4))

story.append(PageBreak())

# ——— 9. КРАТКО ———
story.append(Paragraph("9. Самое главное в одном абзаце", style_h1))
story.append(Spacer(1, 10))
story.append(
    callout(
        "Юнит-экономика = Цена − Все расходы. Сервис собирает <b>15+ статей расходов</b> "
        "автоматически: комиссии, логистику, налоги, резерв на невыкупы, надбавки за "
        "нелокальные продажи. Вам нужно вбить <b>только одну цифру — себестоимость</b>. "
        "И, при желании, ДРР и упаковку. После этого смотрите в таблицу: "
        "<font color='#059669'><b>зелёные строки</b></font> — товары зарабатывают, "
        "<font color='#DC2626'><b>красные</b></font> — теряют. С красными работайте "
        "в первую очередь: поднимайте цену, снижайте себестоимость или снимайте с продажи. "
        "Всё пересчитывается автоматически — кнопку «Обновить» жать не нужно.",
        COLOR_BG_BLUE,
    )
)

story.append(Spacer(1, 30))

story.append(Paragraph("Куда обращаться, если что-то непонятно", style_h2))
story.append(
    bullet_list(
        [
            "<b>Проблемы с данными в таблице</b> — поддержка сервиса",
            "<b>Вопросы по тарифам Ozon</b> — docs.ozon.ru или ваш персональный менеджер Ozon",
            "<b>Вопросы по себестоимости и налогам</b> — ваш бухгалтер",
            "<b>Настройка рекламного ДРР</b> — ваш маркетолог или агентство",
        ]
    )
)

story.append(Spacer(1, 20))
story.append(HRFlowable(width="100%", thickness=0.5, color=COLOR_BORDER))
story.append(Spacer(1, 8))
story.append(
    Paragraph(
        "Документ актуален на версию тарифов Ozon <b>2026-04-06</b>. "
        "При изменении тарифов маркетплейсом расчёты автоматически обновятся после "
        "выкатки новой матрицы.",
        style_small,
    )
)


# ——— Нумерация и шапка ———
def add_page_number(canvas_obj, doc_obj):
    canvas_obj.saveState()
    canvas_obj.setFont("Arial", 9)
    canvas_obj.setFillColor(COLOR_TEXT_MUTED)
    # footer
    canvas_obj.drawRightString(
        A4[0] - 2 * cm,
        1 * cm,
        f"Стр. {doc_obj.page}",
    )
    canvas_obj.drawString(
        2 * cm,
        1 * cm,
        "Юнит-экономика · руководство для менеджера",
    )
    canvas_obj.restoreState()


doc.build(story, onFirstPage=add_page_number, onLaterPages=add_page_number)
print(f"PDF создан: {OUTPUT}")
print(f"Размер: {OUTPUT.stat().st_size / 1024:.1f} KB")
